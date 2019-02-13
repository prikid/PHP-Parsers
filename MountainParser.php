<?php

namespace App\Prikid\Parsers;

use App;
use App\Prikid\PushMessages;
use Goutte\Client as Goutte;
use Symfony\Component\DomCrawler\Crawler;

class MountainParser {

	protected $start_page_url;
	protected $parser;
	protected $crawler;
	protected $products_list=[];
	protected $parsed_products_counter=0;
	protected $max_products_limit;
	protected $saveToDbOnGo = false;
	protected $pusher;

	public function __construct($start_page_url, $max_products_limit=0) {
		$this->max_products_limit = $max_products_limit;
		$this->start_page_url=$start_page_url;
		$this->parser = new Goutte();
		$this->pusher = new PushMessages();
	}

	public function doParsing($saveToDbOnGo=false) {
        $this->pusher->PushMessage('Start parsing products...');

		$this->saveToDbOnGo = $saveToDbOnGo;
		$this->crawler = $this->parser->request('GET', $this->start_page_url);

        $list_pages_counter = 0;
		do {
			set_time_limit(3600*24);
            $list_pages_counter++;
            $this->pusher->PushMessage("Start parsing ProductListPage #$list_pages_counter: {$this->crawler->getUri()}");
			$this->parseProductsListPage();
		} while ( $this->goToNextProductsListPage() && !$this->isEnough() );

		return $this;
	}

	public function saveToDB($products_list=null) {
		if (is_null($products_list))
			$products_list = $this->products_list;

		$data=$this->getDBReadyProductsList($products_list);
		foreach ($data as $row) {
			App\MountainParsedProduct::updateOrCreate(['mnt_id' =>$row['mnt_id']], $row);
		}
		return $this;
	}

	public function getResults() {
		return $this->products_list;
	}

//	public function getDBReadyResults() {
//		return $this->getDBReadyProductsList();
//	}

	public function totalProductsParsed() {
		return $this->parsed_products_counter;
	}

	protected static function getDBReadyProductsList($products_list) {
		$db_products_list = [];

		foreach ( $products_list as $item ) {
			$item_data = [
				'mnt_id'    => $item->id,
				'sku'       => $item->sku,
				'name'      => $item->name,
				'price'     => $item->price,
				'brand'     => $item->brand,
				'product_page_url'  => $item->product_page_url,
				'image_middle_size' => $item->image_middle_size,


				'upc'               => data_get($item, 'detailed.additional_info.upc', ''),
				'department'        => data_get($item, 'detailed.additional_info.department', ''),
				'style' => data_get($item, 'detailed.additional_info.style', ''),
				'color' => data_get($item, 'detailed.additional_info.color', ''),
				'collection' => data_get($item, 'detailed.additional_info.collection', ''),
				'categories' => data_get($item, 'detailed.additional_info.categories', ''),
				'artist' => data_get($item, 'detailed.additional_info.artist', ''),

                'sizes' =>  data_get($item, 'detailed.sizes', ''),

				'main_image_data_json' => json_encode(data_get($item, 'detailed.images.0', [])),
				'images_data_json' => json_encode(data_get($item, 'detailed.images', [])),
				'description' => data_get($item, 'detailed.description', ''),
			];

			$item_data['additional_info_json'] = self::getAdditionalDataJson($item, array_keys($item_data));

			$db_products_list[]=$item_data;
		}
		return $db_products_list;
	}

	protected static function getAdditionalDataJson($item, array $existing_fields) {
		$data=[];
		if (!empty($item->detailed->additional_info)) {
			foreach ( $item->detailed->additional_info as $key=>$value ) {
				if (!in_array($key, $existing_fields)) {
					$data[$key]=$value;
				}
			}
		}

		return json_encode($data);
	}


	protected function parseProductsListPage() {
		$this->crawler->filter('ul.productGrid > li.product')->each(
			function (Crawler $node) {
				if (!$this->isEnough()) {
                    $this->pusher->PushMessage("Start parsing product #" . ($this->parsed_products_counter + 1));
					$product_item_data = $this->scrapeProductListItemInfo($node);
                    $this->pusher->PushMessage("...parsing product ( {$product_item_data->name} ) page " . $product_item_data->product_page_url);
					$product_item_data->detailed = $this->parseProductPage($product_item_data->product_page_url);
					$this->products_list[]=$product_item_data;
					$this->parsed_products_counter++;


					if ($this->saveToDbOnGo) {
						$this->saveToDB([$product_item_data]);
					}

                    $this->pusher->PushMessage("Product successfuly parsed - {$product_item_data->name}");
//					$this->pushProductParsedEvent($product_item_data);
				}
			}
		);
		return $this;
	}

	protected function pushProductParsedEvent($product_item_data) {
		$data = (object) [
			'parsed_products_count' => $this->parsed_products_counter,
			'last_parsed_product_sku' => $product_item_data->sku,
			'last_parsed_product_name' => $product_item_data->name
		];


		event(new App\Events\MountainParser\ProductParsedEvent($data));
	}

	protected function isEnough() {
		return $this->max_products_limit!==0 && $this->parsed_products_counter >= $this->max_products_limit;
	}

	protected function goToNextProductsListPage() {
		$next_page_node_a = $this->crawler->filter('#product-listing-container > div.pagination > ul.pagination-list > li.pagination-item--next > a')->first();
		if ($next_page_node_a->count()>0) {
			$this->crawler = $this->parser->click($next_page_node_a->link());
			return true;
		}
		else
			return false;
	}

	protected function scrapeProductListItemInfo(Crawler $node) {
		$product_data = new \stdClass();
		$product_data->id = $node->attr('data_product_id');
		$product_data->sku = $node->attr('data_product_sku');
		$product_data->name = $node->attr('data_product_name');
		$product_data->price = $node->attr('data_product_price');
		$product_data->brand = $node->attr('data_product_brand');

		$a=$node->filter('article.card > figure.card-figure > a')->first();
		$product_data->product_page_url = $a->attr('href');
		$product_data->image_middle_size = $a->filter('img')->first()->attr('data-src');
		return $product_data;
	}


	protected function parseProductPage($product_page_url) {
		$product_data=new \stdClass();

		$crawler = $this->parser->request('GET', $product_page_url);

		$product_container = $crawler->filter('div.productView');
		$product_view_container = $product_container->filter('div.productView-product');

		if ($product_container->count() && $product_view_container->count()) {
			$html=$product_view_container->html();
			$html=str_replace(['<!--<dl','dl>-->'], ['<dl','dl>'], $html); //Uncomment block with important data for us
			$product_view_container = new Crawler($html);

			$product_data->brand = $product_view_container->filter('h2.productView-brand > a > span')->text();
			$product_data->name = $product_view_container->filter('h1.productView-title')->text();
			$product_data->sku = $product_view_container->filter('dd.productView-info-value[data-product-sku]')->text();
			$product_data->price = $product_view_container->filter('div.productView-price > div.price-section > meta[itemprop="price"]')->attr('content');


			$options = [];
			$product_view_container->filter('div.productView-options div.form-field label.form-option')->each(
			    function(Crawler $node) use (&$options) {
			        $text = $node->filter('span.form-option-variant')->text();
                    $options[] = explode(' ',$text)[0];
                }
            );

			$product_data->sizes = implode(",", $options);


			$product_data->additional_info = $this->scrapeAdditionalProductInfo($product_view_container);

			//Images
			$main_image=$product_container->filter('section.productView-images > figure[data-image-gallery-main]');
			$product_data->images[] = (object)[
				'url' => $main_image->attr('data-zoom-image'),
				'name' => $main_image->filter('a > img.productView-image--default')->attr('alt')
			];

			$product_container->filter('section.productView-images > ul.productView-thumbnails > li.productView-thumbnail')->each(
				function(Crawler $node) use (&$product_data) {
					$a_image=$node->filter('a.productView-thumbnail-link');
					$product_data->images[]=(object)[
						'url' => $a_image->attr('data-image-gallery-zoom-image-url'),
						'name' => $a_image->filter('img')->attr('alt')
					];
				}
			);

			//Description
			$product_data->description = $crawler->filter('#tab-description')->html();
		}
		return $product_data;
	}

	protected function scrapeAdditionalProductInfo($product_view_container){
		$data=[];
		$product_view_container->filter('dl.productView-info > dt')->each(function(Crawler $node) use (&$data) {
			$key = strtolower(str_replace(':','',$node->text()));
			$value = $node->nextAll()->filter('dd')->first()->text();
			$data[$key] = array_key_exists($key, $data) ?  $data[$key].','.$value : $value;
		});
		return (object)$data;
	}
}