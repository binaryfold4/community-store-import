<?php
namespace Concrete\Package\CommunityStoreImport\Src\CommunityStoreImport\Import;

use Concrete\Core\File\FileList;
use Concrete\Core\File\Import\FileImporter;
use Concrete\Core\File\Importer;
use Concrete\Core\File\Service\File;
use Concrete\Core\File\Set\Set;
use Concrete\Core\Http\Client\Client;
use Concrete\Core\Http\HttpServiceProvider;
use Concrete\Core\Support\Facade\Facade;
use Concrete\Core\Support\Facade\Log;
use Concrete\Core\Updater\RemoteApplicationUpdateFactory;
use Concrete\Package\CommunityStore\Src\CommunityStore\Group\Group as StoreGroup;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\Product;
use Doctrine\ORM\Mapping as ORM;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Support\Facade\Events;
use Doctrine\ORM\Repository\RepositoryFactory;
use Ercol\Entity\ErcolProduct;
use Ercol\Entity\ErcolRange;
use Ercol\Entity\Material;
use Ercol\Entity\MaterialType;
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
    protected $removed = 0;
    protected $em = false;
    protected $repositories = [];
    protected $rowLog = [];

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
            'removed' => $this->removed
        ];
    }

    public function processRow($row){

        $this->rowLog = [];

        $action = $row['action'];
        $data = $row['data'];

        switch($action){
            case 'remove':
                $this->removeProduct($data);
                break;
            default:
                $this->syncProduct($data);
        }

        $logs = $this->rowLog;
        arsort($logs);
        Log::addEntry(json_encode($logs));

        return true;
    }

    protected function removeProduct($data)
    {
        if($id = $data['id']){
            if($storeProduct = Product::getByID($id)){
                $storeProduct->remove();
                $this->removed++;
            }
        }
    }

    protected function syncProduct($row){
        // Get attribute headings
        foreach (array_keys($row) as $heading) {
            if (preg_match('/^attr_/', $heading)) {
                $this->attributes[] = $heading;
            }
        }

        $start = microtime(true);
        $p = Product::getBySKU($row['psku']);

        $this->rowLog['Get product'] = (microtime(true)-$start);

        $this->processed++;
        if ($p instanceof Product) {
            $this->update($p, $row);
            $this->updated++;
        } else {
            $p = $this->add($row);
            $this->added++;
        }

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

    protected function getRange(Product $p)
    {
        $productName = $p->getName();
        $parts = explode(' ', $productName, 2);

        $rangeName = $parts[0];

        $start = microtime(true);

        $class = ErcolRange::class;
        /**
         * @var $repository RepositoryFactory
         */
        $rangeRepository = $this->getRespository($class);

        $range = $rangeRepository->findOneBy(['name' => $rangeName]);
        if (!$range) {
            $range = new ErcolRange();
            $range->setName($rangeName);
            $this->em->persist($range);
        }

        $this->rowLog['Get Range'] = (microtime(true)-$start);
        if ($range) {
            return $range;
        }
    }

    protected function createOrUpdateProduct(Product $p){

        $productCode = $p->getSKU();
        $parts = explode('-', $productCode, 2);
        $skuPrefixWithLeadingZeroes = $parts[0];
        $skuPrefix = explode('/', $skuPrefixWithLeadingZeroes, 2)[1];

        $p->setAttribute('product_code', $skuPrefix);

        $class = ErcolProduct::class;
        /**
         * @var $repository RepositoryFactory
         */
        $productRepository = $this->getRespository($class);

        $start = microtime(true);
        $product = $productRepository->findOneBy(['code' => $skuPrefix]);
        if(!$product){
            Log::addEntry('Creating new Product with code ['.$skuPrefix.']');
            $product = new ErcolProduct();
        }

        $this->rowLog['Find Product by code'] =  (microtime(true)-$start);

        if($product) {
            $product->setCode($skuPrefix);
            $product->setName($p->getName());

            if($range = $this->getRange($p)) {
                $product->setRanges([$range]);
            }

            $start = microtime(true);
            $this->createOrUpdateMaterial($p);
            $this->rowLog['CreateOrUpdateMaterial'] = (microtime(true)-$start);
            $start = microtime(true);
            $this->createOrUpdateImage($p);
            $this->rowLog['CreateOrUpdateImage'] = (microtime(true)-$start);

            $this->em->persist($product);
        }
    }

    protected function getMaterialType($name)
    {

        Log::addEntry('Looking for MatType with Name [' . $name . ']');
        $class = MaterialType::class;
        /**
         * @var $repository RepositoryFactory
         */
        $repository = $this->getRespository($class);

        $type = $repository->findOneBy(['name' => $name]);
        if (!$type) {
            Log::addEntry('Creating new matType with Name [' . $name . ']');
            $type = new MaterialType();
            $type->setName($name);
            $this->em->persist($type);
        }

        if ($type) {
            return $type;
        }
    }

    protected function createOrUpdateMaterial(Product $p)
    {
        $code = $p->getAttribute('product_body_fabric');
        if ($code) {
            switch (strtoupper(substr($code, 0, 1))) {
                case 'L':
                    $type = 'Leather';
                    break;
                default:
                    $type = 'Fabric';
                    break;
            }

            $materialType = $this->getMaterialType($type);

            Log::addEntry('Looking for Material with Code [' . $code . ']');
            $class = Material::class;
            /**
             * @var $repository RepositoryFactory
             */
            $repository = $this->getRespository($class);

            $material = $repository->findOneBy(['code' => $code]);
            if (!$material) {
                Log::addEntry('Creating new Material with code [' . $code . ']');
                $material = new Material();
            }

            if ($material) {
                $material->setCode($code);
                if ($materialType) {
                    $material->setMaterialType([$materialType]);
                }

                $this->em->persist($material);
            }
        }
    }

    protected function getImageSet($name)
    {
        if($set = Set::getByName($name)){
            return $set;
        }
    }

    protected function getFile($url){
        $app = Application::getFacadeApplication();

        /**
         * @var $client Client
         */
        $client = $app->make('http/client')->setUri($url);
        $client->getRequest()->setMethod('GET');

        try {
            $response = $client->send();
            if($response->isSuccess()){
                $path = tempnam(sys_get_temp_dir(), 'microd_import');
                if(file_put_contents($path, $response->getBody())){
                    return $path;
                }
            }
            else{
                Log::addEntry('Download failed ['.$response->getStatusCode().']');
            }
        } catch (\Exception $x) {
            $update = null;
        }
    }

    protected function getImageURL(Product $p){
        $imageHost = 'https://dsu7yed5c49c8.cloudfront.net/';
        $imagePath = 'renderimage.aspx?';
        $imageParams = [
            'SKU' => str_replace('/','-', $p->getAttribute('product_code')),
            'Catalog' => 1770,
            'width' => 612,
            'height' => 570
        ];

        if($bodyFabric = $p->getAttribute('product_body_fabric')){
            $imageParams['Body'] = $bodyFabric;
            $imageParams['Scatters'] = $bodyFabric;
        }

        if($finish = $p->getAttribute('product_finish')){
            if($finish == 'CM'){
                $finish = 'CM-Oak';
            }
            $imageParams['Finish'] = $finish;
        }

        $imageURL = $imageHost.$imagePath.http_build_query($imageParams);

        return $imageURL;
    }

    protected function createOrUpdateImage(Product $p)
    {
        $imageURL = $this->getImageURL($p);

        /**
         * @var $imageSet Set
         */
        $imageSet = $this->getImageSet('Store Product Thumbnails');
        $oldImageSet = $this->getImageSet('Material Swatches');
        $image = $p->getImageObj();
        if($image){
            $start  = microtime(true);
            $imageSet->addFileToSet($image);
            $v = $image->getVersion();
            if($v->getAttribute('source_url')!== $imageURL)
            {
                $v->setAttribute('source_url', $imageURL);
            }
            if($oldImageSet){
                $oldImageSet->removeFileFromSet($image);
            }

            $this->rowLog['CreateOrUpdateImage: UpdateExisting'] = (microtime(true)-$start);
            return;
        }

        $imageTitle = $p->getSKU().'.jpg';

        $start = microtime(true);

        // Search for image by fileName (and set)
        $fl = new FileList();
        $fl->getQueryObject()->expr()->eq('fv.fvTitle', $imageTitle);
        $fl->filterBySet($imageSet);
        $fileResults = $fl->getResults();
        $this->rowLog['CreateOrUpdateImage:Find by fvTitle'] = (microtime(true)-$start);
        if(count($fileResults)){
            /**
             * @var $file \Concrete\Core\Entity\File\File
             */
            $file =$fileResults[0];
            $v = $file->getVersion();
            if($v->getAttribute('source_url') != $imageURL){
                $v->setAttribute('source_url', $imageURL);
            }
            $p->setImageID($file->getFileID());
            return;
        }

        $pg = StoreGroup::getByName('Missing Configurator Image');
        $groupID = $pg->getID();
        $pGroupIDs = $p->getGroupIDs();

        $start = microtime(true);
        $fileName = $this->getFile($imageURL);
        $this->rowLog['CreateOrUpdateImage:getFile'] = microtime(true)-$start;
        if($fileName){
            Log::addEntry('Got file, length ['.filesize($fileName).']');
            $app = Application::getFacadeApplication();
            $importer = $app->make(\Concrete\Core\File\Import\FileImporter::class);
            Log::addEntry(get_class($importer));

            $start = microtime(true);
            $file = $importer->importLocalFile($fileName, $p->getSKU().'.jpg');
            $this->rowLog['CreateOrUpdateImage:importLocalFile'] = (microtime(true)-$start);

            $imageSet->addFileToSet($file);
            unlink($fileName);
            $p->setImageID($file->getFileID());

            if(in_array($groupID, $pGroupIDs)) {
                $data = [
                    'pProductGroups' => array_diff($pGroupIDs, [$groupID])
                ];
                ProductGroup::addGroupsForProduct($data, $p);
            }
        }
        else{
            if(!in_array($groupID, $pGroupIDs)) {
                $pGroupIDs[] = $groupID;
                $data = [
                    'pProductGroups' => $pGroupIDs
                ];
                ProductGroup::addGroupsForProduct($data, $p);
            }
        }
    }

    private function setAttributes(Product $product, $row)
    {
        if($this->aks){
            foreach ($this->aks as $ak) {
                $start = microtime(true);
                $akHandle = $ak->getAttributeKeyHandle();
                $this->rowLog['GetAttributeHandle:'.$akHandle] = (microtime(true)-$start);
                $product->setAttribute($ak, $row['attr_'.$akHandle]);
                $this->rowLog['SetAttributes:'.$akHandle] = (microtime(true)-$start);
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

            // Update groups
            ProductGroup::addGroupsForProduct($data, $product);
        }
    }

    private function add($row)
    {
        $row['pprice'] = str_replace(',', '', $row['pprice']);

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
        $row['pprice'] = str_replace(',', '', $row['pprice']);

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

        $start = microtime(true);
        // Product attributes
        $this->setAttributes($p, $row);
        $this->rowLog['Update Attributes'] = (microtime(true)-$start);

        $start = microtime(true);
        // Product groups
        $this->setGroups($p, $row);

        $this->rowLog['Set groups'] = (microtime(true)-$start);
        /**
         * @var $p Product
         */
        $start = microtime(true);
        $p = $p->save();
        $this->rowLog['Save storeproduct'] = (microtime(true)-$start);

        return $p;
    }


}