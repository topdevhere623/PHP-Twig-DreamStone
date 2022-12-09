<?php

namespace DS\Controller\Web\Frontend;

use DS\Core\Utils;
use DS\Model\Composite;
use DS\Model\Shape;
use mongodb\BSON\ObjectID;
use mongodb\BSON\Decimal128;
use DS\Core\Controller\WebController;
use DS\Model\Category;
use DS\Model\Metal;
use DS\Model\BirthStone;
use DS\Model\JewelryStones;
use DS\Model\JewelryPearl;
use DS\Model\JewelryType;
use DS\Model\Product;
use DS\Model\Viewed;
use DS\Model\Favorite;
use DS\Model\StaticPages;
use Slim\Http\Request;
use Slim\Http\Response;

/**
 * Class Front
 * @package DS\Controller\Web
 */
final class Jewelry extends WebController
{

  /**
   * @var array
   */
  private $possibleSort = [
    ['code' => 'price_down', 'title' => 'High to low'],
    ['code' => 'price_up', 'title' => 'Low to high']
  ];

  /**
   *  Home renderer
   *
   * @param Request $request
   * @param Response $response
   * @param $args
   * @return \Psr\Http\Message\ResponseInterface
   * @throws \Exception
   */
  public function indexAction(Request $request, Response $response, $args)
  {
    // ugly way for accessing request attributes
    $this->request = $request;

    return $this->render($response, 'pages/frontend/jewelry/index.twig', [
      'isJewelrySection' => true
    ]);
  }

  /**
   * Filter display
   *
   * @param Request $request
   * @param Response $response
   * @param $args
   * @return \Psr\Http\Message\ResponseInterface
   * @throws \Exception
   */
  public function searchAction(Request $request, Response $response, $args)
  {
    // ugly way for accessing request attributes
    $this->request = $request;

    $Category = new Category($this->mongodb);
    $Product = new Product($this->mongodb);

    $currentCategory = $Category->getOneWhere(['url' => $args['filter'] ?? '']);
    $metals = (new Metal($this->mongodb))->find();
    $jewelrystones = (new JewelryStones($this->mongodb))->find();
    $jewelrypearls = (new JewelryPearl($this->mongodb))->find();
    $categories = $Category->find(['level' => 0]);
    $birthstones = (new BirthStone($this->mongodb))->find();

    $filter = [
      'category' => '',
      'subcategory' => '',
      'subsubcategory' => '',
      'jewelrystone' => [],
      'jewelrypearl' => [],
      'birthstone' => [],
      'metal' => [],
      'price_min' => 0,
      'price_max' => 0,
      'sort_by' => null,
      'offset' => 0, // TODO
      'limit' => 10,
    ];

    $params = $request->getQueryParams();

    foreach ($params as $key => $value)
      if (array_key_exists($key, $filter))
        $filter[$key] = $value;

    $and = [
      ['jewelrytype_id' => new ObjectID((new JewelryType($this->mongodb))->getGeneralTypeId())],
    ];
    $subcategories = [];
    $subsubcategories = [];

    if (!empty($args['filter']) && $args['filter'] != 'all') { // jewelry = all categories
      if (!$currentCategory)
        return $this->render($response->withStatus(404), 'pages/frontend/404.twig');

      $filter['category'] = $currentCategory->url;
      $catId = new ObjectID($currentCategory->_id);
      $subcategories = $Category->allWhere(['parent_id' => $catId]);
      if (empty($filter['subcategory'])) {
        if ($currentCategory->subcategories) {
          $or = [['category_id' => $catId]];
          foreach ($Category->getAllSubcategoriesIds($catId) as $_id) {
            $or[] = ['category_id' => $_id];
          }
          $and[] = ['$or' => $or];
        } else {
          $and[] = ['category_id' => $catId];
        }
      } else {
        $currentSubcategory = $Category->getOneWhere(['url' => $filter['subcategory']]);
        if (!$currentSubcategory)
          return $this->render($response->withStatus(404), 'pages/frontend/404.twig');

        $subCatId = new ObjectID($currentSubcategory->_id);
        $subsubcategories = $Category->allWhere(['parent_id' => $subCatId]);
        if (empty($filter['subsubcategory'])) {
          $and[] = ['category_id' => $subCatId];
        } else {
          $currentSubsubcategory = $Category->getOneWhere(['url' => $filter['subsubcategory']]);
          if (!$currentSubsubcategory)
            return $this->render($response->withStatus(404), 'pages/frontend/404.twig');

          $subSubCatId = new ObjectID($currentSubsubcategory->_id);
          $and[] = ['category_id' => $subSubCatId];
        }
      }
    }

    if (!empty($filter['sort_by'])) {
      $possibleSort = array_map(function ($direction) {
        return $direction['code'];
      }, $this->possibleSort);
      if (!in_array($filter['sort_by'], $possibleSort)) {
        $filter['sort_by'] = null;
      }
    }

    if (!empty($filter['metal']) && $filter['metal'] !== 'All metals') {
      $inparam = explode(',', $filter['metal']);
      $metal = array_filter($metals, function ($metal) use ($inparam) {
        return in_array($metal->code, $inparam);
      });
      $or = [];
      foreach ($metal as $m) {
        $or[] = ['metal_id' => new ObjectID($m->_id)];
      }
      $and[] = ['$or' => $or];
    }

    if (!empty($filter['jewelrystone'])) {
      $inparam = explode(',', $filter['jewelrystone']);
      if (in_array('all', $inparam))
        $and[] = ['jewelrystone_id' => ['$ne' => null]];
      else {
        $or = [];
        foreach ($jewelrystones as $jewelrystone)
          if (in_array($jewelrystone->code, $inparam))
            $or[] = ['jewelrystone_id' => $jewelrystone->_id];
        $and[] = ['$or' => $or];
      }
    }

    if (!empty($filter['jewelrypearl'])) {
      $inparam = explode(',', $filter['jewelrypearl']);
      if (in_array('all', $inparam))
        $and[] = ['jewelrypearl_id' => ['$ne' => null]];
      else {
        $or = [];
        foreach ($jewelrypearls as $jewelrypearl)
          if (in_array($jewelrypearl->code, $inparam))
            $or[] = ['jewelrypearl_id' => $jewelrypearl->_id];
        $and[] = ['$or' => $or];
      }
    }

    if (!empty($filter['birthstone'])) {
      $inparam = explode(',', $filter['birthstone']);
      if (in_array('all', $inparam))
        $and[] = ['birthstone_id' => ['$ne' => null]];
      else {
        $or = [];
        foreach ($birthstones as $birthstone)
          if (in_array($birthstone->code, $inparam))
            $or[] = ['birthstone_id' => $birthstone->_id];
        $and[] = ['$or' => $or];
      }
    }

    $cheapest = $Product->find(empty($and) ? [] : ['$and' => $and], ['sort' => ['price' => 1], 'limit' => 1]);
    $price_min = empty($cheapest) ? 0 : (float)$cheapest[0]->price->__toString();
    $expensive = $Product->find(empty($and) ? [] : ['$and' => $and], ['sort' => ['price' => -1], 'limit' => 1]);
    $price_max = empty($expensive) ? 0 : (float)$expensive[0]->price->__toString();

    if (
      (!empty($filter['price_min']) && is_numeric($filter['price_min']))
      || (!empty($filter['price_max']) && is_numeric($filter['price_max']))
    ) {
      $price = [];
      if (!empty($filter['price_min']) && is_numeric($filter['price_min']))
        $price['$gte'] = new Decimal128($filter['price_min']);
      if (!empty($filter['price_max']) && is_numeric($filter['price_max']))
        $price['$lte'] = new Decimal128($filter['price_max']);
      $and[] = ['price' => $price];
    }

    $user = $request->getAttribute('user');
    $Favorite = new Favorite($this->mongodb, $user ? $user->_id : null);
    $isBuilder = !empty($request->getQueryParam('builder'));

    $find = [
      ['$match' => ['$and' => $and]],
    ];
    if (empty($filter['sort_by'])) {
      $find[] = ['$addFields' => ['sortField' => ['$cond' => [
        ['$ifNull' => ['$order', false]],
        1,
        0
      ]]]];
      $find[] = ['$sort' => ['sortField' => -1, 'order' => 1]];
    } else {
      $sort = explode('_', $filter['sort_by']);
      $find[] = ['$sort' => [$sort[0] => $sort[1] === 'up' ? 1 : -1]];
    }
    $find[] = ['$skip' => (int) $filter['offset']];
    $find[] = ['$limit' => (int) $filter['limit']];
    $products = array_map(function ($product) use ($Product, $Favorite) {
      $Product->populate($product);
      $product->withAttributes = $Product->getSelectedAttributes($product);
      $product->title = $Product->getTitle($product);
      $product->permalink = $Product->getPermalink($product);
      $product->images = $Product->getImages($product);
      $product->isFavorite = $Favorite->isFavorite('products', $product);
      return $product;
    }, $Product->aggregate($find));
    $productsTotal = $Product->countDocuments(['$and' => $and]);

    $metalIds = [];
    foreach ($metals as $metal) {
      $metalIds[$metal->code] = implode('', explode(' ', $metal->code));
    }

    if (isset($params['json'])) {
      if (!count($products)) {
        return $response->withJson([
          'finish' => true,
          'total' => $productsTotal,
        ]);
      } else {
        return $response->withJson([
          'finish' => false,
          'total' => $productsTotal,
          'items' => array_map(function ($product) use ($params) {
            return $this->view->fetch('pages/frontend/jewelry/result.twig', [
              'product' => $product,
              'params' => $params,
            ]);
          }, $products),
        ]);
      }
    }

    $data = [
      'isBuilder' => $isBuilder,
      'products' => $products,
      'total' => $productsTotal,
      'viewedCount' => (new Viewed($this->mongodb))->count(),
      'metals' => $metals,
      'metal_ids' => $metalIds,
      'birthstones' => $birthstones,
      'jewelrystones' => $jewelrystones,
      'jewelrypearls' => $jewelrypearls,
      'categories' => $categories,
      'subcategories' => $subcategories,
      'subsubcategories' => $subsubcategories,
      'possibleSort' => $this->possibleSort,
      'price_min' => $price_min,
      'price_max' => $price_max,
      'filter' => $filter,
      'isJewelryRingsSection' => true,
      'params' => $params,
      'g_event' => 'view_search_results',
    ];

    if ($isBuilder)
      $data['composite'] = (new Composite($this->mongodb))->getDetails();

    return $this->render($response, 'pages/frontend/jewelry/search.twig', $data);
  }

  public function customerAction(Request $request, Response $response, $args)
  {
    // ugly way for accessing request attributes
    $this->request = $request;

    $Product = new Product($this->mongodb);

    $filter = [
      'offset' => 0, // TODO
      'limit' => 10,
    ];

    $params = $request->getQueryParams();
    foreach ($params as $key => $value)
      if (array_key_exists($key, $filter))
        $filter[$key] = $value;

    $options = [];
    $options['limit'] = (int) $filter['limit'];
    $options['skip'] = (int) $filter['offset'];

    $products = $Product->find([
      'customer_images' => ['$gt' => []],
      'hide_customer_creation' => ['$ne' => true],
    ], $options);
    $Product->stringifyValues($products);
    $products = array_map(function ($product) use ($Product) {
      $Product->populate($product);
      if (!empty($product->hide_customer_creation) && count($product->customer_images)) {
        $product->title = $product->title . ' ' . $product->customer_images[0]->text;
      }
      $product->permalink = $Product->getPermalink($product);
      return $product;
    }, $products);

    if (isset($params['json'])) {
      if (!count($products)) {
        return $response->withJson([
          'finish' => true,
        ]);
      } else {
        return $response->withJson([
          'finish' => false,
          'items' => array_map(function ($product) use ($params) {
            return $this->view->fetch('pages/frontend/customer/result.twig', [
              'product' => $product,
              'params' => $params,
            ]);
          }, $products),
        ]);
      }
    }

    $data = [
      'products' => $products,
      'viewedCount' => (new Viewed($this->mongodb))->count(),
      'filter' => $filter,
      'isJewelryRingsSection' => true,
      'params' => $params,
    ];

    return $this->render($response, 'pages/frontend/customer/search.twig', $data);
  }

  /**
   * Display details
   *
   * @param Request $request
   * @param Response $response
   * @param $args
   * @return \Psr\Http\Message\ResponseInterface
   */
  public function detailsAction(Request $request, Response $response, $args)
  {
    // ugly way for accessing request attributes
    $this->request = $request;
    $user = $request->getAttribute('user');
    $userId = $user ? $user->_id : null;

    $filter = [
      'shape' => null,
    ];
    $params = $request->getQueryParams();
    foreach ($params as $key => $value)
      if (array_key_exists($key, $filter))
        $filter[$key] = $value;

    $Product = new Product($this->mongodb);
    $product = $Product->getOneWhere(['url' => $args['product']]);
    if (!$product)
      return $this->render($response, 'pages/frontend/jewelry/details/404.twig', ['text' => 'Product is not found']);

    $Viewed = new Viewed($this->mongodb);
    $Viewed->add('products', $product->_id);

    $urlParams = Utils::getUrlParams($request);
    $Product->populate($product);
    $product->withAttributes = $Product->getSelectedAttributes($product, $urlParams);
    $product->title = $Product->getTitle($product);
    $product->permalink = $Product->getPermalink($product);
    $product->price = $Product->getPrice($product);
    $product->retail_price = $Product->getRetailPrice($product);
    $product->videos = $Product->getVideos($product);
    $product->shippingDetails = $Product->getShippingDetails($product, -5);

    $product->shapes = [];
    $shapeIds = [];
    $filterShapeId = '';
    foreach ($Product->getImages($product, [], true) as $image)
      if (!empty($image->shapes))
        foreach ($image->shapes as $shape)
          if (!in_array($shape->_id, $shapeIds)) {
            $product->shapes[] = $shape;
            $shapeIds[] = $shape->_id;
            if (!empty($filter['shape']) && $filter['shape'] === $shape->code)
              $filterShapeId = $shape->_id;
          }

    $product->images = $Product->getImages($product, $filterShapeId ? [$filterShapeId] : []);

    $productJson = clone $product;
    $productJson->group = 'products';

    $isBuilder = !empty($product->is_for_builder);
    if ($isBuilder && !empty($product->builder_compatible)) {
      $Shape = new Shape($this->mongodb);
      $product->builder_compatible = array_map(function ($shape) use ($Shape) {
        $shape->code = $Shape->getOneWhere(['_id' => $shape->shape_id])->code;
        return $shape;
      }, $product->builder_compatible);
    }
    $data = [
      'isBuilder' => $isBuilder,
      'isFavorite' => (new Favorite($this->mongodb, $userId))->isFavorite('products', $product),
      'meta' => $Product->getMeta($product, $request->getUri()->getBaseUrl()),
      'filter' => $filter,
      'product' => $product,
      'productJson' => json_encode($productJson, JSON_HEX_QUOT),
      'similar' => $Product->getSimilar($product),
      'viewed' => [
        'products' => $Viewed->get('products', $product->_id),
        'productJson' => json_encode($productJson, JSON_HEX_QUOT),
        'diamonds' => $Viewed->get('diamonds'),
      ],
      'staticpages' => (new StaticPages($this->mongodb))->find(),
      'isJewelryRingsSection' => true,
      'params' => $request->getQueryParams(),
      'g_event' => 'view_item',
    ];

    if ($isBuilder)
      $data['composite'] = (new Composite($this->mongodb))->getDetails();

    return $this->render($response, 'pages/frontend/jewelry/details/index.twig', $data);
  }
}