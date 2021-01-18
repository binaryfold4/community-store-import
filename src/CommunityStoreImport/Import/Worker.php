<?php
namespace Concrete\Package\CommunityStoreImport\Src\CommunityStoreImport\Import;

use Concrete\Core\Support\Facade\Facade;
use Concrete\Core\Support\Facade\Log;
use Concrete\Package\CommunityStore\Src\CommunityStore\Group\Group as StoreGroup;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use Doctrine\ORM\Mapping as ORM;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Support\Facade\Events;
use Doctrine\ORM\Repository\RepositoryFactory;
use Ercol\Entity\ErcolProduct;
use Job as AbstractJob;
use Queue;
use ZendQueue\Message as ZendQueueMessage;
use ZendQueue\Queue as ZendQueue;

use Concrete\Core\Support\Facade\Application;
use Concrete\Core\Page\Type\Type as PageType;
use Doctrine\Common\Collections\ArrayCollection;
use Concrete\Core\Page\Template as PageTemplate;
use Concrete\Core\Multilingual\Page\Section\Section;
use Concrete\Core\Support\Facade\DatabaseORM as dbORM;
use Concrete\Package\CommunityStore\Src\CommunityStore\Tax\TaxClass;
use Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Price;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductFile;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductImage;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductGroup;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductEvent;
use \Concrete\Package\CommunityStore\Src\CommunityStore\Utilities\Wholesale;
use Concrete\Package\CommunityStore\Entity\Attribute\Value\StoreProductValue;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductRelated;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductLocation;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductUserGroup;
use Concrete\Package\CommunityStore\Src\CommunityStore\Shipping\Package as StorePackage;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductOption\ProductOption;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductOption\ProductOptionItem;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductVariation\ProductVariation;

use \Concrete\Core\Attribute\ObjectTrait;

class Worker
{
    protected $aks;
    protected $app;
    protected $attributes;
    protected $processed = 0;
    protected $added = 0;
    protected $updated = 0;
    protected $em = false;
    protected $repositories = [];

    public function __construct(){
        $this->app = Facade::getFacadeApplication();
        if (class_exists('\Concrete\Package\CommunityStore\Attribute\Category\ProductCategory')) {
            $productCategory = $this->app->make('Concrete\Package\CommunityStore\Attribute\Category\ProductCategory');
            $this->aks = $productCategory->getList();
        }
    }

    public function getStats(){
        return [
            'processed' => $this->processed,
            'added' => $this->added,
            'updated' => $this->updated,
        ];
    }

    public function processRow($row){
        // Get attribute headings
        foreach (array_keys($row) as $heading) {
            if (preg_match('/^attr_/', $heading)) {
                $this->attributes[] = $heading;
            }
        }

        $p = Product::getBySKU($row['psku']);

        $this->processed++;
        if ($p instanceof Product) {
            $this->update($p, $row);
            $this->updated++;
        } else {
            $p = $this->add($row);
            $this->added++;
        }

        Log::addEntry('Syncing product ['.$p->getSKU().']');
        $this->createOrUpdateProduct($p);

        return $p;
    }

    protected function getRespository($class)
    {
        if(!$this->em) {
            $this->em = dbORM::entityManager();
        }

        if($repository = $this->repositories[$class]){
            return $repository;
        }
        else{
            if($repository = $this->em->getRepository($class)) {
                $this->repositories[$class] = $repository;
            }
        }

        return  $repository;
    }

    protected function createOrUpdateProduct(Product $p){

        $productCode = $p->getSKU();
        $parts = explode('-', $productCode, 2);
        $skuPrefixWithLeadingZeroes = $parts[0];
        $skuPrefix = explode('/', $skuPrefixWithLeadingZeroes, 2)[1];

        Log::addEntry('Looking for Product with Code ['.$skuPrefix.']');
        $class = ErcolProduct::class;
        /**
         * @var $repository RepositoryFactory
         */
        $productRepository = $this->getRespository($class);

        $product = $productRepository->findOneBy(['code' => $skuPrefix]);
        if(!$product){
            Log::addEntry('Creating new Product with code ['.$skuPrefix.']');
            $product = new ErcolProduct();
        }

        if($product) {
            $product->setCode($skuPrefix);
            $product->setName($p->getName());

            $range = $this->getRange();

            $this->em->persist($product);
        }
    }

    private function setAttributes($product, $row)
    {
        if($this->aks){
            foreach ($this->aks as $ak) {
                $product->setAttribute($ak, $row['attr_'.$ak->getAttributeKeyHandle()]);
            }
        }
        else {
            foreach ($this->attributes as $attr) {
                $ak = preg_replace('/^attr_/', '', $attr);
                if (StoreProductKey::getByHandle($ak)) {
                    $product->setAttribute($ak, $row[$attr]);
                }
            }
        }
    }

    private function setGroups($product, $row) {
        /**
         * @var $product Product
         */
        if ($row['pproductgroups']) {
            $pGroupNames = explode(',', $row['pproductgroups']);
            $pGroupIDs = array();
            foreach ($pGroupNames as $pGroupName) {
                $pg = StoreGroup::getByName($pGroupName);
                if (!$pg instanceof StoreGroup) {
                    $pg = StoreGroup::add($pGroupName);
                }
                if($pg) {
                    $pGroupIDs[] = $pg->getID();
                }
            }
            $data['pProductGroups'] = array_filter(array_unique($pGroupIDs));

            Log::addInfo('Adding groups ['.implode(',', $data['pProductGroups']).'] to product ['.$product->getSKU().']');

            // Update groups
            ProductGroup::addGroupsForProduct($data, $product);
        }
    }

    private function add($row)
    {
        $data = array(
            'pSKU' => $row['psku'],
            'pName' => $row['pname'],
            'pDesc' => trim($row['pdesc']),
            'pDetail' => trim($row['pdetail']),
            'pCustomerPrice' => $row['pcustomerprice'],
            'pFeatured' => $row['pfeatured'],
            'pQty' => $row['pqty'],
            'pNoQty' => $row['pnoqty'],
            'pTaxable' => $row['ptaxable'],
            'pActive' => $row['pactive'],
            'pShippable' => $row['pshippable'],
            'pCreateUserAccount' => $row['pcreateuseraccount'],
            'pAutoCheckout' => $row['pautocheckout'],
            'pExclusive' => $row['pexclusive'],

            'pPrice' => $row['pprice'],
            'pSalePrice' => $row['psaleprice'],
            'pPriceMaximum' => $row['ppricemaximum'],
            'pPriceMinimum' => $row['ppriceminimum'],
            'pPriceSuggestions' => $row['ppricesuggestions'],
            'pQtyUnlim' => $row['pqtyunlim'],
            'pBackOrder' => $row['pbackorder'],
            'pLength' => $row['plength'],
            'pWidth' => $row['pwidth'],
            'pHeight' => $row['pheight'],
            'pWeight' => $row['pweight'],
            'pNumberItems' => $row['pnumberitems'],

            // CS v1.4.2+
            'pMaxQty' => $row['pmaxqty'],
            'pQtyLabel' => $row['pqtylabel'],
            'pAllowDecimalQty' => (isset($row['pallowdecimalqty']) ? $row['pallowdecimalqty'] : false),
            'pQtySteps' => $row['pqtysteps'],
            'pSeperateShip' => $row['pseperateship'],
            'pPackageData' => $row['ppackagedata'],

            // CS v2+
            'pQtyLabel' => (isset($row['pqtylabel']) ? $row['pqtylabel'] : ''),
            'pMaxQty' => (isset($row['pmaxqty']) ? $row['pmaxqty'] : 0),

            // Not supported in CSV data
            'pfID' => intval(Config::get('community_store_import.default_image')),
            'pVariations' => false,
            'pQuantityPrice' => false,
            'pTaxClass' => 1        // 1 = default tax class
        );

        // Save product
        $p = Product::saveProduct($data);

        // Add product attributes
        $this->setAttributes($p, $row);

        // Add product groups
        $this->setGroups($p, $row);

        return $p;
    }

    private function update($p, $row)
    {
        if ($row['psku']) $p->setSKU($row['psku']);
        if ($row['pname']) $p->setName($row['pname']);
        if ($row['pdesc']) $p->setDescription($row['pdesc']);
        if ($row['pdetail']) $p->setDetail($row['pdetail']);
        if ($row['pfeatured']) $p->setIsFeatured($row['pfeatured']);
        if ($row['pqty']) $p->setQty($row['pqty']);
        if ($row['pnoqty']) $p->setNoQty($row['pnoqty']);
        if ($row['ptaxable']) $p->setISTaxable($row['ptaxable']);
        if ($row['pactive']) $p->setIsActive($row['pactive']);
        if ($row['pshippable']) $p->setIsShippable($row['pshippable']);
        if ($row['pcreateuseraccount']) $p->setCreatesUserAccount($row['pcreateuseraccount']);
        if ($row['pautocheckout']) $p->setAutoCheckout($row['pautocheckout']);
        if ($row['pexclusive']) $p->setIsExclusive($row['pexclusive']);

        if ($row['pprice']) $p->setPrice($row['pprice']);
        if ($row['psaleprice']) $p->setSalePrice($row['psaleprice']);
        if ($row['ppricemaximum']) $p->setPriceMaximum($row['ppricemaximum']);
        if ($row['ppriceminimum']) $p->setPriceMinimum($row['ppriceminimum']);
        if ($row['ppricesuggestions']) $p->setPriceSuggestions($row['ppricesuggestions']);
        if ($row['pqtyunlim']) $p->setIsUnlimited($row['pqtyunlim']);
        if ($row['pbackorder']) $p->setAllowBackOrder($row['pbackorder']);
        if ($row['plength']) $p->setLength($row['plength']);
        if ($row['pwidth']) $p->setWidth($row['pwidth']);
        if ($row['pheight']) $p->setHeight($row['pheight']);
        if ($row['pweight']) $p->setWeight($row['pweight']);
        if ($row['pnumberitems']) $p->setNumberItems($row['pnumberitems']);

        // CS v1.4.2+
        if ($row['pmaxqty']) $p->setMaxQty($row['pmaxqty']);
        if ($row['pqtylabel']) $p->setQtyLabel($row['pqtylabel']);
        if ($row['pallowdecimalqty']) $p->setAllowDecimalQty($row['pallowdecimalqty']);
        if ($row['pqtysteps']) $p->setQtySteps($row['pqtysteps']);
        if ($row['pseparateship']) $p->setSeparateShip($row['pseparateship']);
        if ($row['ppackagedata']) $p->setPackageData($row['ppackagedata']);

        if (!$p->getImageId())
            $p->setImageId(intval(Config::get('community_store_import.default_image')));

        // Product attributes
        $this->setAttributes($p, $row);

        // Product groups
        $this->setGroups($p, $row);

        /**
         * @var $p Product
         */
        $p = $p->save();

        return $p;
    }


}