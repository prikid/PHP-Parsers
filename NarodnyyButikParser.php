<?php
/**
 * Created by PhpStorm.
 * User: Sergii
 * Date: 07.08.2018
 * Time: 17:40
 */

namespace App\Prikid\Parsers;

use App;
use Goutte\Client as Goutte;
use Symfony\Component\DomCrawler\Crawler;

class NarodnyyButikParserProductData {
	public $product_id;
	public $name='';
	public $price=0;
	public $url='';
	public $category_name='';
	public $category_url='';
	public $sku='';
	public $attributes=[];
	public $images=[];
	public $sizes=[];
}

class NarodnyyButikParser {

	protected $start_page_url = 'https://n-butik.com';
	protected $parser;
	protected $crawler;
	protected $products_list=[];
	protected $parsed_products_counter=0;
	protected $max_products_limit;
	protected $saveToDbOnGo = false;
	protected $categories=[];

	public function __construct($max_products_limit=0) {
		$this->max_products_limit = $max_products_limit;
		$this->parser = new Goutte();
	}

	public function doParsing($saveToDbOnGo=false) {
		set_time_limit(3600);

		$this->saveToDbOnGo = $saveToDbOnGo;
		$this->crawler = $this->parser->request('GET', $this->start_page_url);

		$this->parseCatalogMenuForCategories();

		//$this->categories = array_slice($this->categories,0,2);

		foreach ($this->categories as $category) {
			$this->crawler = $this->parser->request('GET', $category->url);

			do {
				$this->parseProductsListPage($category);
			} while ( $this->goToNextProductsListPage() && !$this->isEnough() );
		}
		return $this;
	}

	public function saveToDB($products_list=null) {
		if (is_null($products_list))
			$products_list = $this->products_list;

		$data=$this->getDBReadyProductsList($products_list);
		foreach ($data as $row) {
			App\NarodnyyButikParsedProduct::updateOrCreate($row);
		}
		return $this;
	}

	public function totalProductsParsed() {
		return $this->parsed_products_counter;
	}

	protected static function getDBReadyProductsList($products_list) {
		$db_products_list = [];

		foreach ( $products_list as $item ) {
			$item_data = [
				'product_id'    => $item->product_id,
				'sku'       => $item->sku,
				'name'      => $item->name,
				'price'     => $item->price,
				'product_page_url'  => $item->url,
				'category_name' => $item->category_name,
				'category_url' => $item->category_url,
				'images_json' => json_encode($item->images, JSON_UNESCAPED_UNICODE),
				'attributes_json' => json_encode($item->attributes, JSON_UNESCAPED_UNICODE),
				'sizes_json' => json_encode($item->sizes, JSON_UNESCAPED_UNICODE),
			];

			$db_products_list[]=$item_data;
		}
		return $db_products_list;
	}

	protected function parseCatalogMenuForCategories() {
		$this->crawler->filter('#menu-item-3921 > ul.sub-menu li.menu-item:not(.menu-item-has-children)')->each(
			function (Crawler $node) {
				$category = new \stdClass();
				$a = $node->filter('a')->first();
				$category->name = $a->text();
				$category->url = $a->attr('href');

				$this->categories[] = $category;
			}
		);
		return $this;
	}

	protected function parseProductsListPage($category) {
		$this->crawler->filter('ul.products > li.product')->each(
			function (Crawler $node) use ($category) {
				if (!$this->isEnough()) {

					preg_match("/post-(\d{1,6})/", $node->attr('class'), $matches);
					if (isset($matches[1]) && $this->isAlreadyParsed($matches[1]))
						return;

					$url = $node->filter('a')->first()->attr('href');

					try {
						$product_data = $this->parseProductPage($url);
						if ($product_data === false)
							return;

						$product_data->category_name = $category->name;
						$product_data->category_url = $category->url;
						$this->products_list[]=$product_data;
						$this->parsed_products_counter++;

						if ($this->saveToDbOnGo) {
							$this->saveToDB([$product_data]);
						}
					}
					catch (\Exception $e) {
						$this->pushProductParsedEvent($e->getMessage());
						return;
					}

					$this->pushProductParsedEvent($product_data);
				}
			}
		);
		return $this;
	}

	protected function parseProductPage($product_page_url) {
		$product_data=new NarodnyyButikParserProductData();

		$crawler = $this->parser->request('GET', $product_page_url);
		$product_container = $crawler->filter('div.product_wrapper');

		$product_data->product_id = $this->getNodeAttr( $product_container->filter('form.variations_form'),'data-product_id' );
		if (!$product_data->product_id)
			$product_data->product_id = $this->getNodeAttr( $product_container->filter('a.single_add_to_wishlist'),'data-product-id' );

		if ($this->isAlreadyParsed($product_data->product_id))
			return false;

		$product_data->url = $product_page_url;
		$product_container->filter('div.woocommerce-product-gallery__image')->each(
			function($node) use (&$product_data){
				$product_data->images[] = $this->getNodeAttr($node->filter('a')->first(),'href');
			}
		);


		$product_data->name = $this->getNodeText( $product_container->filter('h1.product_title')->first() );

		$second_name = $this->getNodeText( $product_container->filter('#tab-description h2')->first());
		if ($second_name)
			$product_data->name .= ':'.$second_name;




		$out_of_stock = $product_container->filter('form.variations_form p.out-of-stock')->count() > 0;

		if ($out_of_stock) {
			$product_data->price = 0;
			$product_data->sizes = [];
		}
		else {
			$product_data->price = (float)$this->getNodeText( $product_container->filter('p.price span.amount')->first() );

			//product options
			$product_data->sizes=[];
			$product_container->filter('table.variations select > option')->each(
				function($node) use (&$product_data) {
					$value = $this->getNodeAttr( $node, 'value' );
					if ($value!=='' && $value!=='pid-zamovlennya')
						$product_data->sizes[] = $value;
				}
			);
		}

		$product_container->filter('#tab-additional_information > table.shop_attributes tr')->each(
			function($node) use (&$product_data) {
				$name = $this->getNodeText($node->filter('th'));
				$value = $this->getNodeText( $node->filter('td'));
				if ($name=='Артикул')
					$product_data->sku = $value;
				else
					$product_data->attributes[] = $name.':'.$value;
			}
		);

		return $product_data;
	}

	protected function getNodeAttr($node, $attr_name) {
		return $node->count()?$node->attr($attr_name):'';
	}

	protected function getNodeText($node) {
		return $node->count()?$node->text():'';
	}

	protected function isEnough() {
		return $this->max_products_limit!==0 && $this->parsed_products_counter >= $this->max_products_limit;
	}

	protected function goToNextProductsListPage() {
		$pager = $this->crawler->filter('div.pager');
		if ($pager->count()) {
			$next_page_node_a = $this->crawler->filter('div.pager span.page-numbers.current')->nextAll()->filter('a.page-numbers')->first();
			if ($next_page_node_a->count()) {
				$this->crawler = $this->parser->click( $next_page_node_a->link() );
				return true;
			}
		}
		return false;
	}

	protected function pushProductParsedEvent($product_data, $type='info') {
		$data = [
			'type' => $type,
			'count' => $this->parsed_products_counter,
			'id' => $product_data->product_id,
			'sku' => $product_data->sku,
			'name' => $product_data->name

		];

		event(new App\Events\GeneralPusherEvent(json_encode($data, JSON_UNESCAPED_UNICODE)));
	}

	protected function isAlreadyParsed( $product_id ) {
		foreach ($this->products_list as $product) {
			if ($product->product_id == $product_id)
				return true;
		}
		return false;
	}

}