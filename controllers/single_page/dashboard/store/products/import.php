<?php
namespace Concrete\Package\CommunityStoreImport\Controller\SinglePage\Dashboard\Store\Products;

use Concrete\Core\Controller\Controller;
use Concrete\Core\Job\QueueableJob;
use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\Package\Package as Package;
use Concrete\Core\File\File;
use Concrete\Core\Support\Facade\DatabaseORM as dbORM;
use Concrete\Core\Support\Facade\Facade;
use Concrete\Package\CommunityStore\Src\CommunityStore\Product\ProductList;
use Concrete\Package\CommunityStoreImport\Src\CommunityStoreImport\Import\Worker;
use Doctrine\Common\Persistence\ObjectRepository;
use Doctrine\ORM\Repository\RepositoryFactory;
use Ercol\Entity\ErcolProduct;
use Log;
use Exception;
use Config;
use Core;
use Queue;
use Symfony\Component\HttpFoundation\JsonResponse;
use ZendQueue\Message;


class Import extends DashboardPageController
{
    public $helpers = array('form', 'concrete/asset_library', 'json');
    private $attributes = array();

    public function view()
    {
        $this->loadFormAssets();
        $this->set('pageTitle', t('Product Import'));

//        $this->removeInactiveProducts();
    }

    public function loadFormAssets()
    {
        $this->requireAsset('core/file-manager');
        $this->requireAsset('core/sitemap');
        $this->requireAsset('css', 'select2');
        $this->requireAsset('javascript', 'select2');
        $this->set('concrete_asset_library', Core::make('helper/concrete/asset_library'));
        $this->set('form', Core::make('helper/form'));
    }

    protected function removeInactiveProducts(){
        $spl = new ProductList();
        $spl->setActiveOnly(false);

        $spl->filter('pActive', 0, '=');
        $inactiveProductIDS = $spl->getResultIDs();

        /**
         * @var $q \ZendQueue\Queue
         */
        $q = Queue::get('community_store_import', array('timeout' => 60));
        if(!$q->count()){
            foreach($inactiveProductIDS as $inactiveProductID){
                $message = [
                    'action' => 'remove',
                    'data' => ['id' => $inactiveProductID]
                ];

                $q->send(json_encode($message));
            }
        }
    }

    protected function updateMetaData(){

        $this->em = dbORM::entityManager();

        /**
         * @var $repository ObjectRepository
         */
        $repository = $this->em->getRepository(ErcolProduct::class);

        $products = $repository->findAll();

        $queued = 0;
        /**
         * @var $q \ZendQueue\Queue
         */
        $q = Queue::get('community_store_import', array('timeout' => 60));
        if($q){
            foreach($products as $product){
                /**
                 * @var $product ErcolProduct
                 */
                $message = [
                    'action' => 'metadata',
                    'data' => ['id' => $product->getId()]
                ];

                $q->send(json_encode($message));
                $queued++;
            }
        }

        return $queued;
    }

    protected function createUpdateProductPage(){

        $this->em = dbORM::entityManager();

        /**
         * @var $repository ObjectRepository
         */
        $repository = $this->em->getRepository(ErcolProduct::class);

        $products = $repository->findAll();

        /**
         * @var $q \ZendQueue\Queue
         */
        $q = Queue::get('community_store_import', array('timeout' => 60));
        if(!$q->count()){
            foreach($products as $product){
                /**
                 * @var $product ErcolProduct
                 */
                $message = [
                    'action' => 'page',
                    'data' => ['id' => $product->getId()]
                ];

                $q->send(json_encode($message));
            }
        }
    }

    public function run()
    {
        $this->saveSettings();

//        $this->removeInActiveProducts();
//        $this->createUpdateProductPage();
//        $count = $this->updateMetaData();

        $this->set('success', $this->get('success') . "Queued {$count} products to update metadata");

        $f = \File::getByID(Config::get('community_store_import.import_file'));
        if($f) {
            $this->importFromFile($f);

            $this->set('success', $this->get('success') . "Import queued: $addedToQueue added, $total rows.");
        }

        Log::addNotice($this->get('success'));
    }

    protected function importFromFile(\Concrete\Core\Entity\File\File $f)
    {
        $fname = $_SERVER['DOCUMENT_ROOT'] . $f->getApprovedVersion()->getRelativePath();

        if (!file_exists($fname) || !is_readable($fname)) {
            $this->error->add(t("Import file not found or is not readable."));
            return;
        }

        if (!$handle = @fopen($fname, 'r')) {
            $this->error->add(t('Cannot open file %s.', $fname));
            return;
        }

        $MAX_TIME = Config::get('community_store_import.max_execution_time');
        $MAX_EXECUTION_TIME = ini_get('max_execution_time');
        $MAX_INPUT_TIME = ini_get('max_input_time');
        $MAX_ROWS = Config::get('community_store_import.max_rows');

        ini_set('max_execution_time', $MAX_TIME);
        ini_set('max_input_time', $MAX_TIME);
        ini_set('auto_detect_line_endings', TRUE);

        $delim = Config::get('community_store_import.csv.delimiter');
        if(!$delim){
            $delim = ',';
        }
        $delim = ($delim === '\t') ? "\t" : $delim;

        $enclosure = Config::get('community_store_import.csv.enclosure');
        if(!$enclosure){
            $enclosure = '"';
        }
        $line_length = Config::get('community_store_import.csv.line_length');

        // Get headings
        $csv = fgetcsv($handle, $line_length, $delim, $enclosure);
        $headings = array_map('trim', $csv);

        $headingsMap = [
            'CatNo.' => 'attr_product_code',
            'ModelNo.' => 'ModelNo.',
            'Description' => 'pName',
            'Range' => 'attr_product_range',
            'Fabric' => 'attr_product_body_fabric',
            'Fabric Grade' => 'Fabric Grade',
            'Finish' => 'attr_product_finish',
            'RRP' => 'pPrice',
            'LeadTime (Days)' => 'attr_lead_time_days',
            'Product Sku' => 'pSKU',
            'Product On Winman?' => 'Product On Winman?',
            'Scatter Prefix if Applicable' =>  'attr_product_scatter_sku_prefix',
            'Number of Scatters' => 'attr_scatter_quantity',
            'Product Id' => '',
            'Product Description' => '',
            'DC 001 TXT' => '',
            'DC 002 TXT' => '',
            'DC 004 TXT' => '',
            'Boxed Length' => 'attr_product_boxed_length',
            'Box 1 Height' => 'attr_product_boxed_height',
            'Box 1 Width' => 'attr_product_boxed_width',
            'Box 1 Weight' => 'attr_product_boxed_weight',
            'Pack Size' => '',
            'Dimension Quantity' => '',
            'Location' => '',
//            'Finish' => '',
//            'Fabric' => '',
            'Sku for Cubes' => '',
            'Part number' => '',
            'Old_design no' => '',
            'Description_' => '',
            'Second Description' => '',
            'Status' => '',
            'Factory Location' => '',
            'LENGTH' => '',
            'WIDTH' => '',
            'HEIGHT' => '',
            'KG' => '',
            'CUBE Mtr' => '',
            'CUBE Ft' => '',
            'Sac' => '',
            'Collection Description Copy' => 'attr_product_collection_description',
            'Marketing / Designer Product Copy' => 'attr_product_marketing_description',
            'exclusive_to' => '',
            'exclusive_url' => '',
            'Technical Descripton' => 'attr_product_technical_description',
            'Timber' => 'attr_product_timber',
            'Fabric Collection' => '',
            'Designed by' => 'attr_designed_by',
            'Country of Origin' => 'attr_country_of_origin',
            'dim_width' => 'attr_product_width',
            'dim_min_width' => 'attr_product_min_width',
            'dim_max_width' => 'attr_product_max_width',
            'dim_depth' => 'attr_product_depth',
            'dim_min_depth' => 'attr_product_min_depth',
            'dim_max_depth' => 'attr_product_max_depth',
            'dim_height' => 'attr_product_height',
            'dim_min_height' => 'attr_product_min_height',
            'dim_max_height' => 'attr_product_max_height',
            'dim_seat_height' => 'attr_product_seat_height',
            'dim_seat_depth' => 'attr_product_seat_depth',
            'dim_seat_width (arm to arm)' => 'attr_product_seat_width_arm_to_arm',
            'dim_diameter' => 'attr_product_diameter',
            'dim_clearance_under' => 'attr_product_clearance_under',
            'dim_arm_height' => 'attr_product_arm_height',
            'dim_table_leg_gap' => 'attr_product_table_leg_gap',
            'Measurements between shelves' => 'attr_product_measurement_between_shelves',
            'unpacked_weight_kg' => 'attr_product_unpacked_weight',
            'seat_interior' => 'attr_product_seat_interior',
            'back_interior' => 'attr_product_back_interior',
            'scatter_interior' => 'attr_product_scatter_interior',
            'Reversible Cushions' => 'attr_product_scatter_interior',
            'Reclines' => 'attr_product_reclines',
            'Max Loading Weight' => 'attr_product_max_loading_weight',
            'assembly' => 'attr_product_assembly',
            'Timber & Construction' => 'attr_product_construction',
            'Care Instructions' => 'attr_product_care_instructions',
            'Additional Information' => 'attr_product_additional_info',
            'Product Sheet Download' => '',
            'Assembly Instructions Download Link' => 'attr_product_assembly_instructions_link',
            'Availble From' => 'available_from',
            'Available to' => 'available_to',
            'Exclusive to' => 'attr_exclusive_to'
        ];

        $headingsRewrite = array_map(function($heading)use($headingsMap){
            if(isset($headingsMap[$heading])){
                $heading = trim($headingsMap[$heading]);
            }
            return $heading;
        }, $headings);

        $headingsRewrite = array_map('strtolower', $headingsRewrite);
        $defaults = [
            'pqty' => 0,
            'pqtyunlim' => 1,
            'pnoqty' => 0, // If set to 1, you can only add one of these into the basket
            'ptaxable' => 1,
            'pactive' => 1,
            'pshippable' => 1,
            'pcreateuseraccount' => 0,
            'pautocheckout' => 0,
            'pexclusive' => 0,
            'pallowdecimalqty' => 0,
            'attr_import_file_id' => $f->getFileID(),
        ];

        if ($this->isValid($headings)) {
            $this->error->add(t("Required data missing."));
            return;
        }

        $addedToQueue = 0;
        $failedToQueue = 0;
        $total = 0;
        /**
         * @var $q \ZendQueue\Queue
         */
        $q = Queue::get('community_store_import', array('timeout' => 60));

        while (($csv = fgetcsv($handle, $line_length, $delim, $enclosure)) !== FALSE) {
            if (count($csv) === 1) {
                continue;
            }

            if($MAX_ROWS){
                if($MAX_ROWS == $total){
                    break;
                }
            }

            // Make associative arrray
            $row = array_combine($headingsRewrite, $csv);
            $row = array_merge($defaults, $row);

            $message = [
                'action' => 'sync',
                'data' => $row
            ];

            if($q->send(json_encode($message))){
                $addedToQueue++;
            }
            else{
                $failedToQueue++;
            }

            $total++;
        }

        return $addedToQueue;
    }

    public function process()
    {
        session_write_close();


        $w = new Worker();

        /**
         * @var $q \ZendQueue\Queue
         */
        $q = Queue::get('community_store_import', array('timeout' => 10));
        if($q->count()){
            for($i = 0; $i<10; $i++){
                $messages = $q->receive(2);
                foreach($messages as $message){
                    /**
                     * @var $message Message
                     */
                    $data = json_decode($message->body, JSON_OBJECT_AS_ARRAY);
                    if($w->processRow($data)){
                        $q->deleteMessage($message);
                    }
                }
            }
        }

        if($w){
            $stats = $w->getStats();
            $stats['count'] = $q->count();
        }

        $r = new JsonResponse();
        $r->setData($stats);

        return $r;
    }

    private function saveSettings()
    {
        $data = $this->post();

        // @TODO: Validate post data

        Config::save('community_store_import.import_file', $data['import_file']);
        Config::save('community_store_import.default_image', $data['default_image']);
        Config::save('community_store_import.max_execution_time', $data['max_execution_time']);
        Config::save('community_store_import.max_rows', $data['max_rows']);
        Config::save('community_store_import.csv.delimiter', $data['delimiter']);
        Config::save('community_store_import.csv.enclosure', $data['enclosure']);
        Config::save('community_store_import.csv.line_length', $data['line_length']);
    }

    private function isValid($headings)
    {
        // @TODO: implement

        // @TODO: interrogate database for non-null fields
        $dbname = Config::get('database.connections.concrete.database');

        /*
            SELECT GROUP_CONCAT(column_name) nonnull_columns
            FROM information_schema.columns
            WHERE table_schema = '$dbname'
                AND table_name = 'CommunityStoreProducts'
                AND is_nullable = 'NO'
                // pfID is excluded because it is not-null but also an optional field
                AND column_name not in ('pID', 'pfID', pDateAdded');
        */

        return (false);
    }
}

