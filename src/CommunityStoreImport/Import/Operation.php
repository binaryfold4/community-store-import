<?php
namespace Concrete\Package\CommunityStoreImport\Src\CommunityStoreImport\Import;

use Doctrine\ORM\Mapping as ORM;
use Concrete\Core\Support\Facade\Config;
use Concrete\Core\Support\Facade\Events;
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

/**
 * @ORM\Entity
 * @ORM\Table(name="CommunityStoreImportSessions")
 */
class Session
{
    use ObjectTrait;
    /**
     * @ORM\Id @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    protected $sID;

//    /**
//     * @ORM\Column(type="integer")
//     */
//    protected $importfvID;
//
//    /**
//     * @ORM\Column(type="integer")
//     */
//    protected $importuID;

    /**
     * @ORM\Column(type="text",nullable=true)
     */
    protected $sessionDetail;

    /**
     * @ORM\Column(type="integer")
     */
    protected $sessionTotal;

    /**
     * @ORM\Column(type="integer")
     */
    protected $sessionComplete;

    /**
     * @ORM\Column(type="integer")
     */
    protected $sessionFailed;

    /**
     * @ORM\Column(type="integer")
     */
    protected $sessionSuccess;

    /**
     * @ORM\OneToMany(targetEntity="Concrete\Package\CommunityStoreImport\Src\CommunityStoreImport\Import\Operation", mappedBy="session", cascade={"delete"})
     */
    protected $operations;

    /**
     * @ORM\ManyToOne (targetEntity="Concrete\Core\User"))
     * @ORM\JoinColumn(name="importuID", referencedColumnName="uID")
     */
    protected $user;

    /**
     * @ORM\ManyToOne (targetEntity="Concrete\Core\Entity\File"))
     * @ORM\JoinColumn(name="importfvID", referencedColumnName="fvID")
     */
    protected $fileVersion ;

    /**
     * @return self
     */
    public static function getByID($pID)
    {
        $em = dbORM::entityManager();

        return $em->find(get_class(), $pID);
    }

    public function getAttributes()
    {
        return $this->getObjectAttributeCategory()->getAttributeValues($this);
    }

    public static function saveProduct($data)
    {
        if ($data['pID']) {
            //if we know the pID, we're updating.
            $product = self::getByID($data['pID']);
            $product->setPageDescription($data['pDesc']);

            if ($data['pDateAdded_dt']) {
                $product->setDateAdded(new \DateTime($data['pDateAdded_dt'] . ' ' . $data['pDateAdded_h'] . ':' . $data['pDateAdded_m'] . (isset($data['pDateAdded_a']) ? $data['pDateAdded_a'] : '')));
            }
        } else {
            //else, we don't know it and we're adding a new product
            $product = new self();
            $product->setDateAdded(new \DateTime());
        }
        $product->setName($data['pName']);
        $product->setSKU($data['pSKU']);
        $product->setBarCode($data['pBarcode']);
        $product->setDescription($data['pDesc']);
        $product->setDetail($data['pDetail']);
        $product->setPrice($data['pPrice']);

        if ($data['pWholesalePrice'] !== '') {
            $product->setWholesalePrice($data['pWholesalePrice']);
        } else {
            $product->setWholesalePrice( '');
        }

        if ($data['pSalePrice'] !== '') {
            $product->setSalePrice($data['pSalePrice']);
        } else {
            $product->setSalePrice('');
        }

        if ($data['pSaleStart_dt']) {
            $product->setSaleStart(new \DateTime($data['pSaleStart_dt'] . ' ' . $data['pSaleStart_h'] . ':' . $data['pSaleStart_m']  . (isset($data['pSaleStart_a']) ? $data['pSaleStart_a'] : '')));
        } else {
            $product->setSaleStart(null);
        }

        if ($data['pSaleEnd_dt']) {
            $product->setSaleEnd(new \DateTime($data['pSaleEnd_dt'] . ' ' . $data['pSaleEnd_h'] . ':' . $data['pSaleEnd_m']  . (isset($data['pSaleEnd_a']) ? $data['pSaleEnd_a'] : '') ));
        }else {
            $product->setSaleEnd(null);
        }

        $product->setIsFeatured($data['pFeatured']);
        $product->setQty($data['pQty']);
        $product->setIsUnlimited($data['pQtyUnlim']);
        $product->setAllowBackOrder($data['pBackOrder']);
        $product->setNoQty($data['pNoQty']);
        $product->setTaxClass($data['pTaxClass']);
        $product->setIsTaxable($data['pTaxable']);
        $product->setImageID($data['pfID']);
        $product->setIsActive($data['pActive']);
        $product->setCreatesUserAccount($data['pCreateUserAccount']);
        $product->setIsShippable($data['pShippable']);
        $product->setWidth($data['pWidth']);
        $product->setHeight($data['pHeight']);
        $product->setStackedHeight($data['pStackedHeight']);
        $product->setLength($data['pLength']);
        $product->setWeight($data['pWeight']);
        $product->setPackageData($data['pPackageData']);
        $product->setNumberItems($data['pNumberItems']);
        $product->setSeperateShip($data['pSeperateShip']);
        $product->setAutoCheckout($data['pAutoCheckout']);
        $product->setIsExclusive($data['pExclusive']);
        $product->setCustomerPrice($data['pCustomerPrice']);
        $product->setPriceSuggestions($data['pPriceSuggestions']);
        $product->setPriceMaximum($data['pPriceMaximum']);
        $product->setPriceMinimum($data['pPriceMinimum']);
        $product->setQuantityPrice($data['pQuantityPrice']);
        $product->setAllowDecimalQty($data['pAllowDecimalQty']);
        $product->setQtySteps($data['pQtySteps'] > 0 ? $data['pQtySteps'] : null);
        $product->setQtyLabel($data['pQtyLabel']);
        $product->setMaxQty($data['pMaxQty']);
        $product->setPageID($data['pageCID']);
        $product->setNotificationEmails($data['pNotificationEmails']);
        $product->setOrderCompleteCID($data['pOrderCompleteCID']);

        if ($data['pDateAvailableStart_dt']) {
            $product->setDateAvailableStart(new \DateTime($data['pDateAvailableStart_dt'] . ' ' . $data['pDateAvailableStart_h'] . ':' . $data['pDateAvailableStart_m'] . (isset($data['pDateAvailableStart_a']) ? $data['pDateAvailableStart_a'] : '')));
        }else {
            $product->setDateAvailableStart(null);
        }

        if ($data['pDateAvailableEnd_dt']) {
            $product->setDateAvailableEnd(new \DateTime($data['pDateAvailableEnd_dt'] . ' ' . $data['pDateAvailableEnd_h'] . ':' . $data['pDateAvailableEnd_m'] . (isset($data['pDateAvailableEnd_a']) ? $data['pDateAvailableEnd_a'] : '')));
        }else {
            $product->setDateAvailableEnd(null);
        }

        $product->setOutOfStockMessage($data['pOutOfStockMessage']);
        $product->setAddToCartText($data['pAddToCartText']);

        if ($data['pManufacturer']) {
            $manufacturer = Manufacturer::getByID($data['pManufacturer']);
        } else {
            $manufacturer = null;
        }

        $product->setManufacturer($manufacturer);


        // if we have no product groups, we don't have variations to offer
        if (empty($data['poName'])) {
            $product->setHasVariations(0);
        } else {
            $product->setHasVariations($data['pVariations']);
        }

        $product->save();
        if (!$data['pID']) {
            $product->generatePage($data['selectPageTemplate']);
        } else {
            $product->updatePage();
        }

        return $product;
    }

    public function getID()
    {
        return $this->pID;
    }

    public function setID($id)
    {
        $this->pID = $id;
    }

    public function getName()
    {
        return $this->pName;
    }

    public function getSKU()
    {
        if ($this->hasVariations() && $variation = $this->getVariation()) {
            if ($variation) {
                $varsku = $variation->getVariationSKU();

                if ($varsku) {
                    return $varsku;
                } else {
                    return $this->pSKU;
                }
            }
        } else {
            return $this->pSKU;
        }
    }

    public function getPageID()
    {
        return $this->cID;
    }

    public function getProductPage()
    {
        if ($this->getPageID()) {
            $pageID = $this->getPageID();
            $productPage = Page::getByID($pageID);
            if ($productPage && !$productPage->isError() && !$productPage->isInTrash()) {

                $c = Page::getCurrentPage();
                $lang = Section::getBySectionOfSite($c);

                if (is_object($lang)) {
                    $relatedID = $lang->getTranslatedPageID($productPage);

                    if ($relatedID && $relatedID != $pageID) {
                        $translatedPage = Page::getByID($relatedID);

                        if ($translatedPage && !$translatedPage->isError() && !$translatedPage->isInTrash()) {
                            $productPage = $translatedPage;
                        }
                    }
                }

                return $productPage;
            }
        }

        return false;
    }

    public function getDescription()
    {
        return $this->pDesc;
    }

    public function getDesc()
    {
        return $this->pDesc;
    }

    public function getDetail()
    {
        return $this->pDetail;
    }

    public function getBasePrice()
    {
        return $this->pPrice;
    }

    // set ignoreDiscounts to true to get the undiscounted price
    public function getPrice($qty = 1, $ignoreDiscounts = false)
    {
        if ($this->hasVariations() && $variation = $this->getVariation()) {
            if ($variation) {
                $varprice = $variation->getVariationPrice();

                if ($varprice) {
                    $price = $varprice;
                } else {
                    $price = $this->getQuantityAdjustedPrice($qty);
                }
            }
        } else {
            $price = $this->getQuantityAdjustedPrice($qty);
        }

        $price += $this->getPriceAdjustment();

        $discounts = $this->getDiscountRules();

        if (!$ignoreDiscounts) {
            if (!empty($discounts)) {
                foreach ($discounts as $discount) {
                    $discount->setApplicableTotal($price);
                    $discountedprice = $discount->returnDiscountedPrice();

                    if (false !== $discountedprice) {
                        $price = $discountedprice;
                    }
                }
            }
        }

        return $price;
    }

    public function getWholesalePriceValue() {
        return $this->pWholesalePrice;
    }

    public function getWholesalePrice($qty = 1)
    {
        $price = $this->pPrice;

        if ($this->hasVariations() && $variation = $this->getVariation()) {
            if ($variation) {
                $varWholesalePrice = $variation->getVariationWholesalePrice();

                if ($varWholesalePrice) {
                    $price = $varWholesalePrice;
                }
            }
        } else {
            $price = $this->pWholesalePrice;

            if (!$price) {
                $price = $this->pPrice;
            }
        }

        $priceAdjustment = $this->getPriceAdjustment();

        if ($price && $priceAdjustment != 0) {
            return $price + $priceAdjustment;
        }

        return $price;
    }

    private function getQuantityAdjustedPrice($qty = 1) {
        if ($this->hasQuantityPrice()) {
            $priceTiers = $this->getPriceTiers();

            if (count($priceTiers) > 0) {
                foreach ($priceTiers as $pt) {
                    if ($qty >= $pt->getFrom() && $qty <= $pt->getTo()) {
                        return $pt->getPrice();
                    }
                }

                if ($qty >= $pt->getFrom()) {
                    return $pt->getPrice();
                }
            }
        }

        return $this->pPrice;
    }

    public function getFormattedOriginalPrice()
    {
        return Price::format($this->getPrice(1));
    }

    public function getFormattedPrice()
    {
        return Price::format($this->getActivePrice());
    }

    public function getFormattedWholesalePrice()
    {
        return Price::format($this->getWholesalePrice());
    }

    public function getSalePriceValue() {
        return $this->pSalePrice;
    }

    public function getSalePrice()
    {

        $saleStart = $this->getSaleStart();
        $saleEnd = $this->getSaleEnd();
        $now = new \DateTime();

        if ($saleStart && $saleStart > $now) {
            return false;
        }

        if ($saleEnd && $now > $saleEnd) {
            return false;
        }

        if ($this->hasVariations() && $variation = $this->getVariation()) {
            if ($variation) {
                $varprice = $variation->getVariationSalePrice();
                if ($varprice) {
                    $price = $varprice;
                } else {
                    $price = $this->pSalePrice;
                }
            }
        } else {
            $price = $this->pSalePrice;
        }

        $priceAdjustment = $this->getPriceAdjustment();

        if ($price && $priceAdjustment != 0) {
            return $price + $priceAdjustment;
        }
        return $price;
    }

    public function getFormattedSalePrice()
    {
        $saleprice = $this->getSalePrice();

        if ('' != $saleprice) {
            return Price::format($saleprice);
        }
    }

    public function getSaleStart()
    {
        return $this->pSaleStart;
    }

    public function setSaleStart($saleStart)
    {
        $this->pSaleStart = $saleStart;
    }

    public function getSaleEnd()
    {
        return $this->pSaleEnd;
    }

    public function setSaleEnd($saleEnd)
    {
        $this->pSaleEnd = $saleEnd;
    }

    public function getActivePrice($qty = 1)
    {
        if(Wholesale::isUserWholesale()){
            return $this->getWholesalePrice();
        } else {
            $salePrice = $this->getSalePrice();
            if ($salePrice != "" && !$this->hasQuantityPrice()) {
                return $salePrice;
            }
            return $this->getPrice($qty);
        }
    }

    public function getFormattedActivePrice($qty = 1)
    {
        return Price::format($this->getActivePrice($qty));
    }

    public function getTaxClassID()
    {
        return $this->pTaxClass;
    }

    public function getTaxClass()
    {
        return TaxClass::getByID($this->pTaxClass);
    }

    public function isTaxable()
    {
        return (bool) $this->pTaxable;
    }

    public function isFeatured()
    {
        return (bool) $this->pFeatured;
    }

    public function isActive()
    {
        return (bool) $this->pActive;
    }

    public function isShippable()
    {
        return (bool) $this->pShippable;
    }

    public function allowCustomerPrice()
    {
        return (bool) $this->pCustomerPrice;
    }

    public function hasQuantityPrice()
    {
        return (bool) $this->pQuantityPrice;
    }

    public function getQuantityPrice()
    {
        return $this->pQuantityPrice;
    }

    public function setQuantityPrice($bool)
    {
        $this->pQuantityPrice = (!is_null($bool) ? $bool : false);
    }

    public function getDimensions($whl = null)
    {
        $width = $this->getWidth();
        $height = $this->getHeight();
        $length = $this->getLength();

        if ($this->hasVariations() && $variation = $this->getVariation()) {
            $varWidth = $variation->getVariationWidth();
            $varHeight = $variation->getVariationHeight();
            $varLength = $variation->getVariationLength();

            if ('' != $varWidth) {
                $width = $varWidth;
            }

            if ('' != $varHeight) {
                $height = $varHeight;
            }

            if ('' != $varLength) {
                $length = $varLength;
            }
        }

        switch ($whl) {
            case "w":
                return $width;
                break;
            case "h":
                return $height;
                break;
            case "l":
                return $length;
                break;
            default:

                $dimensions = [];

                if ($length > 0) {
                    $dimensions[] = $length;
                }

                if ($width > 0) {
                    $dimensions[] = $width;
                }

                if ($height > 0) {
                    $dimensions[] = $height;
                }

                return implode('&times;', $dimensions);
                break;
        }
    }

    public function getWidth()
    {
        if ($this->hasVariations() && $variation = $this->getVariation()) {
            $width = $variation->getVariationWidth();

            if ($width) {
                return $width;
            }
        }

        return $this->pWidth;
    }

    public function getHeight()
    {
        if ($this->hasVariations() && $variation = $this->getVariation()) {
            $height = $variation->getVariationHeight();

            if ($height) {
                return $height;
            }
        }

        return $this->pHeight;
    }


    public function getStackedHeight()
    {
        return $this->pStackedHeight;
    }

    public function getLength()
    {
        if ($this->hasVariations() && $variation = $this->getVariation()) {
            $length = $variation->getVariationLength();

            if ($length) {
                return $length;
            }
        }

        return $this->pLength;
    }

    public function getWeight()
    {
        $weight = $this->pWeight;

        if ($this->hasVariations() && $variation = $this->getVariation()) {
            $varWeight = $variation->getVariationWeight();
            if ($varWeight) {
                $weight = $varWeight;
            }
        }

        $weight += $this->getWeightAdjustment();
        return $weight;
    }

    public function getNumberItems()
    {
        $numberItems = $this->pNumberItems;

        if ($this->hasVariations() && $variation = $this->getVariation()) {
            $varNumberItems = $variation->getVariationNumberItems();

            if ($varNumberItems) {
                return $varNumberItems;
            } else {
                return $numberItems;
            }
        } else {
            return $numberItems;
        }
    }

    public function getPackages()
    {
        $packages = [];

        $packagedata = $this->getPackageData();

        if ($packagedata) {
            $lines = explode("\n", $packagedata);

            foreach ($lines as $line) {
                $line = strtolower($line);
                $line = str_replace('x', ' ', $line);
                $line = str_replace('-', ' ', $line);
                $values = preg_split('/[\s]+/', $line);

                $package = new StorePackage();
                $package->setWeight($values[0]);
                $package->setWidth($values[1]);
                $package->setHeight($values[2]);
                $package->setLength($values[3]);

                $packages[] = $package;
            }
        } else {
            $package = new StorePackage();
            $package->setWeight($this->getWeight());
            $package->setWidth($this->getLength());
            $package->setHeight($this->getWidth());
            $package->setLength($this->getHeight());

            $packages[] = $package;
        }

        return $packages;
    }

    public function getImageID()
    {
        if ($this->hasVariations() && $variation = $this->getVariation()) {
            $id = $variation->getVariationImageID();
            if (!$id) {
                return $this->pfID;
            } else {
                return $id;
            }
        } else {
            return $this->pfID;
        }
    }

    public function getImageObj()
    {
        if ($this->getImageID()) {
            $fileObj = File::getByID($this->getImageID());

            return $fileObj;
        }
    }

    public function getBaseProductImageID()
    {
        return $this->pfID;
    }

    public function getBaseProductImageObj()
    {
        if ($this->getBaseProductImageID()) {
            $fileObj = File::getByID($this->getBaseProductImageID());

            return $fileObj;
        }
    }

    public function hasDigitalDownload()
    {
        return count($this->getDownloadFiles()) > 0 ? true : false;
    }

    public function getDownloadFiles()
    {
        return ProductFile::getFilesForProduct($this);
    }

    public function getDownloadFileObjects()
    {
        return ProductFile::getFileObjectsForProduct($this);
    }

    public function createsLogin()
    {
        return (bool) $this->pCreateUserAccount;
    }

    public function allowQuantity()
    {
        return !(bool) $this->pNoQty;
    }

    public function isExclusive()
    {
        return (bool) $this->pExclusive;
    }

    public function hasVariations()
    {
        return (bool) $this->pVariations;
    }

    public function isUnlimited($skipDateCheck = false)
    {
        if (!$skipDateCheck) {
            $now = new \DateTime();
            $startAvailable = $this->getDateAvailableStart();
            $endAvailable = $this->getDateAvailableEnd();

            if ($startAvailable && $startAvailable >= $now) {
                return false;
            }

            if ($endAvailable && $now > $endAvailable) {
                return false;
            }
        }


        if ($this->hasVariations() && $variation = $this->getVariation()) {
            return $variation->isUnlimited();
        } else {
            return (bool) $this->pQtyUnlim;
        }
    }

    public function autoCheckout()
    {
        return (bool) $this->pAutoCheckout;
    }

    public function allowBackOrders()
    {
        return (bool) $this->pBackOrder;
    }

    public function hasUserGroups()
    {
        return count($this->getUserGroups()) > 0 ? true : false;
    }

    public function getUserGroupIDs()
    {
        return ProductUserGroup::getUserGroupIDsForProduct($this);
    }

    public function getImage()
    {
        $fileObj = $this->getImageObj();
        if (is_object($fileObj)) {
            return "<img src='" . $fileObj->getRelativePath() . "'>";
        }
    }

    public function getImageThumb()
    {
        $fileObj = $this->getImageObj();
        if (is_object($fileObj)) {
            return "<img src='" . $fileObj->getThumbnailURL('file_manager_listing') . "'>";
        }
    }

    public function getStockLevel() {

        $now = new \DateTime();
        $startAvailable = $this->getDateAvailableStart();
        $endAvailable = $this->getDateAvailableEnd();

        if ($startAvailable && $startAvailable >= $now) {
            return 0;
        }

        if ($endAvailable && $now > $endAvailable) {
            return 0;
        }

        if ($this->hasVariations() && $variation = $this->getVariation()) {
            return $variation->getVariationQty();
        } else {
            return $this->pQty;
        }
    }

    /**
     * @deprecated
     */
    public function getQty()
    {
        return $this->getStockLevel();
    }

    public function getMaxCartQty()
    {
        if ($this->allowBackOrders() || $this->isUnlimited()) {
            $available = false;
        } else {
            $available = $this->getStockLevel();
        }

        $maxcart = $this->getMaxQty();

        if ($maxcart > 0) {
            if ($available > 0) {
                return min($maxcart, $available);
            } else {
                return $maxcart;
            }
        } else {
            return $available;
        }
    }

    public function isSellable()
    {
        if (!$this->isActive()) {
            return false;
        }

        $now = new \DateTime();
        $startAvailable = $this->getDateAvailableStart();
        $endAvailable = $this->getDateAvailableEnd();

        if ($startAvailable && $startAvailable >= $now) {
            return false;
        }

        if ($endAvailable && $now > $endAvailable) {
            return false;
        }

        if ($this->hasVariations() && $variation = $this->getVariation()) {
            return $variation->isSellable();
        } else {
            if ($this->getStockLevel() > 0 || $this->isUnlimited()) {
                return true;
            } else {
                if ($this->allowBackOrders()) {
                    return true;
                } else {
                    return false;
                }
            }
        }
    }

    public function getimagesobjects()
    {
        return ProductImage::getImageObjectsForProduct($this);
    }

    public function getLocationPages()
    {
        return ProductLocation::getLocationsForProduct($this);
    }

    public function getGroupIDs()
    {
        return ProductGroup::getGroupIDsForProduct($this);
    }

    public function getDateAdded()
    {
        return $this->pDateAdded;
    }

    public function save()
    {
        $em = dbORM::entityManager();
        $em->persist($this);
        $em->flush();
    }

    public function delete()
    {
        $em = dbORM::entityManager();
        $em->remove($this);
        $em->flush();
    }

    public function remove()
    {
        // create product event and dispatch
        $event = new ProductEvent($this);
        Events::dispatch(ProductEvent::PRODUCT_DELETE, $event);

        ProductImage::removeImagesForProduct($this);
        ProductOption::removeOptionsForProduct($this);
        ProductOptionItem::removeOptionItemsForProduct($this);
        ProductFile::removeFilesForProduct($this);
        ProductGroup::removeGroupsForProduct($this);
        ProductLocation::removeLocationsForProduct($this);
        ProductUserGroup::removeUserGroupsForProduct($this);
        ProductVariation::removeVariationsForProduct($this);

        $em = dbORM::entityManager();
        $attributes = $this->getAttributes();

        foreach($attributes as $attribute) {
            $em->remove($attribute);
        }

        $em->remove($this);
        $em->flush();

        $page = Page::getByID($this->cID);
        if (is_object($page)) {
            $page->delete();
        }
    }

    public function __clone()
    {
        if ($this->shallowClone) {
            return;
        }

        if ($this->pID) {
            $this->setId(null);
            $this->setPageID(null);

            $locations = $this->getLocations();
            $this->locations = new ArrayCollection();
            if (count($locations) > 0) {
                foreach ($locations as $loc) {
                    $cloneLocation = clone $loc;
                    $this->locations->add($cloneLocation);
                    $cloneLocation->setProduct($this);
                }
            }

            $groups = $this->getGroups();
            $this->groups = new ArrayCollection();
            if (count($groups) > 0) {
                foreach ($groups as $group) {
                    $cloneGroup = clone $group;
                    $this->groups->add($cloneGroup);
                    $cloneGroup->setProduct($this);
                }
            }

            $images = $this->getImages();
            $this->images = new ArrayCollection();
            if (count($images) > 0) {
                foreach ($images as $image) {
                    $cloneImage = clone $image;
                    $this->images->add($cloneImage);
                    $cloneImage->setProduct($this);
                }
            }

            $files = $this->getFiles();
            $this->files = new ArrayCollection();
            if (count($files) > 0) {
                foreach ($files as $file) {
                    $cloneFile = clone $file;
                    $this->files->add($cloneFile);
                    $cloneFile->setProduct($this);
                }
            }

            $userGroups = $this->getUserGroups();
            $this->userGroups = new ArrayCollection();
            if (count($userGroups) > 0) {
                foreach ($userGroups as $userGroup) {
                    $cloneUserGroup = clone $userGroup;
                    $this->userGroups->add($cloneUserGroup);
                    $cloneUserGroup->setProduct($this);
                }
            }

            $options = $this->getOptions();
            $this->options = new ArrayCollection();
            if (count($options) > 0) {
                foreach ($options as $option) {
                    $cloneOption = clone $option;
                    $this->options->add($cloneOption);
                    $cloneOption->setProduct($this);
                }
            }
        }
    }

    public function duplicate($newName, $newSKU = '')
    {
        $newproduct = clone $this;
        $newproduct->setIsActive(false);
        $newproduct->setQty(0);
        $newproduct->setName($newName);
        $newproduct->setSKU($newSKU);

        $existingPageID = $this->getPageID();
        if ($existingPageID) {
            $existinPage = Page::getByID($existingPageID);
            $pageTemplateID = $existinPage->getPageTemplateID();
            $newproduct->generatePage($pageTemplateID);
        }

        $newproduct->setDateAdded(new \DateTime());
        $newproduct->save();

        $attributes = $this->getAttributes();
        if (count($attributes)) {
            foreach ($attributes as $att) {
                $ak = $att->getAttributeKey();
                if ($ak && is_object($ak)) {
                    $value = $att->getValue();

                    if (is_object($value) && !is_subclass_of($value,  'Concrete\Core\Entity\File\File')) {
                        $newvalue = clone $value;
                    } else {
                        $newvalue = $value;
                    }
                    $newproduct->setAttribute($ak->getAttributeKeyHandle(), $newvalue);
                }
            }
        }

        $variations = $this->getVariations();
        $newvariations = [];

        if (count($variations) > 0) {
            foreach ($variations as $variation) {
                $cloneVariation = clone $variation;
                $cloneVariation->setProductID($newproduct->getID());
                $cloneVariation->save(true);
                $newvariations[] = $cloneVariation;
            }
        }

        $optionMap = [];

        foreach ($newproduct->getOptions() as $newoption) {
            foreach ($newoption->getOptionItems() as $optionItem) {
                $optionMap[$optionItem->originalID] = $optionItem;
            }
        }

        foreach ($newvariations as $variation) {
            foreach ($variation->getOptions() as $option) {
                $optionid = $option->getOptionItem()->getID();
                $option->setOptionItem($optionMap[$optionid]);
                $option->save(true);
            }
        }

        $relatedProducts = $this->getRelatedProducts();
        if (count($relatedProducts)) {
            $related = [];
            foreach ($relatedProducts as $relatedProduct) {
                $related[] = $relatedProduct->getRelatedProductID();
            }
            ProductRelated::addRelatedProducts(['pRelatedProducts' => $related], $newproduct);
        }

        $em = dbORM::entityManager();
        $em->flush();

        // create product event and dispatch
        $event = new ProductEvent($this, $newproduct);
        Events::dispatch(ProductEvent::PRODUCT_DUPLICATE, $event);

        return $newproduct;
    }

    public function generatePage($templateID = null)
    {
        $app = Application::getFacadeApplication();
        $pkg = $app->make('Concrete\Core\Package\PackageService')->getByHandle('community_store');
        $targetCID = Config::get('community_store.productPublishTarget');

        if ($targetCID > 0) {
            $parentPage = Page::getByID($targetCID);
            $pageType = PageType::getByHandle('store_product');

            if ($pageType && $parentPage && !$parentPage->isError() && !$parentPage->isInTrash()) {
                $pageTemplate = $pageType->getPageTypeDefaultPageTemplateObject();

                if ($pageTemplate) {
                    if ($templateID) {
                        $pt = PageTemplate::getByID($templateID);
                        if (is_object($pt)) {
                            $pageTemplate = $pt;
                        }
                    }
                    $newProductPage = $parentPage->add(
                        $pageType,
                        [
                            'cName' => $this->getName(),
                            'pkgID' => $pkg->getPackageID(),
                        ],
                        $pageTemplate
                    );
                    $newProductPage->setAttribute('exclude_nav', 1);

                    $this->savePageID($newProductPage->getCollectionID());
                    $this->setPageDescription($this->getDesc());

                    $csm = $app->make('cs/helper/multilingual');
                    $mlist = Section::getList();

                    // if we have multilingual pages to also create
                    if (count($mlist) > 1) {
                        foreach ($mlist as $m) {
                            $relatedID = $m->getTranslatedPageID($parentPage);

                            if (!empty($relatedID) && $targetCID != $relatedID) {
                                $parentPage = Page::getByID($relatedID);
                                $translatedPage = $newProductPage->duplicate($parentPage);

                                $productName = $csm->t(null, 'productName', $this->getID(), false, $m->getLocale());

                                if ($productName) {
                                    $translatedPage->update(['cName' => $productName]);
                                }

                                $pageDescription = trim($translatedPage->getAttribute('meta_description'));
                                $newDescription = $csm->t(null, 'productDescription', $this->getID(), false, $m->getLocale());

                                if ($newDescription && !$pageDescription) {
                                    $translatedPage->setAttribute('meta_description', strip_tags($newDescription));
                                }
                            }
                        }
                    }

                    return true;
                }
            }
        }

        return false;
    }

    public function updatePage()
    {
        $pageID = $this->getPageID();

        if ($pageID) {
            $page = Page::getByID($pageID);

            if ($page && !$page->isError() && $page->getCollectionName() != $this->getName()) {
                $page->updateCollectionName($this->getName());
            }
        }
    }

    public function setPageDescription($newDescription)
    {
        $productDescription = strip_tags(trim($this->getDesc()));
        $pageID = $this->getPageID();
        if ($pageID) {
            $productPage = Page::getByID($pageID);
            if (is_object($productPage) && $productPage->getCollectionID() > 0) {
                $pageDescription = trim($productPage->getAttribute('meta_description'));
                // if it's the same as the current product description, it hasn't been updated independently of the product
                if ('' == $pageDescription || $productDescription == $pageDescription) {
                    $productPage->setAttribute('meta_description', strip_tags($newDescription));
                }
            }
        }
    }

    public function setPageID($cID)
    {
        $this->setCollectionID($cID);
    }

    public function savePageID($cID)
    {
        $this->setCollectionID($cID);
        $this->save();
    }

    /* TO-DO
     * This isn't completely accurate as an order status may be incomplete and never change,
     * or an order may be canceled. So at somepoint, circle back to this to check for certain status's
     */
    public function getTotalSold()
    {
        $app = Application::getFacadeApplication();
        $db = $app->make('database')->connection();
        $results = $db->GetAll("SELECT * FROM CommunityStoreOrderItems WHERE pID = ?", $this->pID);

        return count($results);
    }

    public function getObjectAttributeCategory()
    {
        return Application::getFacadeApplication()->make('\Concrete\Package\CommunityStore\Attribute\Category\ProductCategory');
    }

    public function getAttributeValueObject($ak, $createIfNotExists = false)
    {
        $category = $this->getObjectAttributeCategory();

        if (!is_object($ak)) {
            $ak = $category->getByHandle($ak);
        }

        $value = false;
        if (is_object($ak)) {
            $value = $category->getAttributeValue($ak, $this);
        }

        if ($value) {
            return $value;
        } elseif ($createIfNotExists) {
            $attributeValue = new StoreProductValue();
            $attributeValue->setProduct($this);
            $attributeValue->setAttributeKey($ak);
            return $attributeValue;
        }
    }


    public function getVariationData()
    {
        $firstAvailableVariation = false;
        $adjustment = 0;
        $availableOptionsids = [];
        $foundOptionids = [];

        if ($this->hasVariations()) {
            $availableOptionsids = [];
            foreach ($this->getVariations() as $variation) {
                $foundOptionids = [];
                $adjustment = 0;
                $isAvailable = false;

                if ($variation->isSellable()) {
                    $variationOptions = $variation->getOptions();

                    foreach ($variationOptions as $variationOption) {
                        $opt = $variationOption->getOptionItem();

                        $foundOptionids[] = $variationOption->getOptionItem()->getOption()->getID() ;

                        if ($opt->isHidden()) {
                            $isAvailable = false;
                            break;
                        } else {
                            $isAvailable = true;
                            $adjustment += $opt->getPriceAdjustment();
                        }
                    }
                    if ($isAvailable) {
                        $availableOptionsids = $variation->getOptionItemIDs();

                        $this->shallowClone = true;
                        $firstAvailableVariation = clone $this;
                        $firstAvailableVariation->setVariation($variation);

                        break;
                    }
                }
            }
        }

        foreach($this->getOptions() as $option) {
            if (!in_array($option->getID(), $foundOptionids)) {
                $optionItems = $option->getOptionItems();

                foreach ($optionItems as $optionItem) {
                    if (!$optionItem->isHidden()) {
                        $adjustment += $optionItem->getPriceAdjustment();
                        break;
                    }
                }
            }
        }

        return ['firstAvailableVariation' => $firstAvailableVariation, 'availableOptionsids' => $availableOptionsids, 'priceAdjustment'=>$adjustment];
    }

    // helper function for working with variation options
    public function getVariationLookup()
    {
        $variationLookup = [];

        if ($this->hasVariations()) {
            $variations = $this->getVariations();

            $variationLookup = [];

            if (!empty($variations)) {
                foreach ($variations as $variation) {
                    // returned pre-sorted
                    $ids = $variation->getOptionItemIDs();
                    $variationLookup[implode('_', $ids)] = $variation;
                }
            }
        }

        return $variationLookup;
    }
}