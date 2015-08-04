<?php

/**
 * This file is part of the Receiptful extension.
 *
 * (c) Receiptful <info@receiptful.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Igor Goltsov <igor@ecomgems.com>
 */
class Receiptful_Core_Helper_Coupon extends Mage_Core_Helper_Abstract
{
    protected $_validations = array();

    public function getRuleName(array $upsell)
    {
        $nameParts = array(
            "Receiptful Coupons:"
        );

        if ($upsell['upsellType'] == 'discountcoupon'){

            $amountLabel = $upsell["amount"];

            if ($upsell['couponType'] == 2){
                $amountLabel .= "%";
            } elseif ($upsell['couponType'] == 1) {
                $amountLabel .= Mage::app()->getBaseCurrencyCode();
            }

            $nameParts[] = sprintf("Discount - %s", $amountLabel);

        } elseif ($upsell['upsellType'] == 'shippingcoupon') {

            $nameParts[] = "Free Shipping";
        }

        return implode(" ", $nameParts);
    }

    public function getCouponRule(array $upsell)
    {
        $name = $this->getRuleName($upsell);

        /** @var Mage_SalesRule_Model_Resource_Rule_Collection $rules */
        $rules = Mage::getModel('salesrule/rule')->getCollection();
        $rules->addFieldToFilter('name', $name);
        if ($rules->getSize()) {
            $rule = $rules->getFirstItem();
            return $rule;
        }

        return false;
    }

    /**
     * Validate Coupon Code for Expiration
     *
     * @param $couponCode
     * @return bool
     */
    public function isValid($couponCode)
    {
        if (!isset($this->_validations[$couponCode])) {

            # Is invalid by default
            $this->_validations[$couponCode] = false;

            /** @var $coupon Mage_SalesRule_Model_Coupon */
            $coupon = Mage::getModel('salesrule/coupon');
            $coupon->loadByCode($couponCode);

            if ($coupon->getId()) {

                if ($coupon->getExpirationDate()){

                    # Change validity flag if we found the coupon and it can be expired

                    $currentDate = new Zend_Date();
                    $currentDateStr = $currentDate->toString(Varien_Date::DATETIME_INTERNAL_FORMAT);

                    $isValid = ($currentDateStr < $coupon->getExpirationDate());
                    $this->_validations[$couponCode] = $isValid;
                } else {

                    $this->_validations[$couponCode] = true;
                }
            }
        }

        return $this->_validations[$couponCode];
    }

    protected function handleShippingCoupon(array $upsell, $rule)
    {
        $rule
            ->setSimpleAction(Mage_SalesRule_Model_Rule::BY_FIXED_ACTION)
            ->setDiscountAmount(0)
            ->setSimpleFreeShipping(Mage_SalesRule_Model_Rule::FREE_SHIPPING_ITEM);

    }

    protected function handleDiscountCoupon(array $upsell, $rule)
    {
        $simpleAction = $upsell['couponType'] === 1 ?
            Mage_SalesRule_Model_Rule::BY_FIXED_ACTION :
            Mage_SalesRule_Model_Rule::BY_PERCENT_ACTION;

        $rule
            ->setSimpleAction($simpleAction)
            ->setDiscountAmount($upsell['amount']);
    }

    public function createCouponRule(array $upsell)
    {
        $description = $this->getRuleName($upsell);

        $handlers = array(
            'discountcoupon' => array($this, 'handleDiscountCoupon'),
            'shippingcoupon' => array($this, 'handleShippingCoupon')
        );

        if (!array_key_exists($upsell['upsellType'], $handlers)) {
            return false;
        }

        // All customer group ids
        $customerGroupIds = Mage::getModel('customer/group')->getCollection()->getAllIds();

        // SalesRule Rule model
        $rule = Mage::getModel('salesrule/rule');

        $websiteIds = array_map(
            function ($website) {
                return $website->getId();
            },
            Mage::app()->getWebsites()
        );

        $rule
            ->setName($description)
            ->setDescription($description)
            ->setCouponType(Mage_SalesRule_Model_Rule::COUPON_TYPE_SPECIFIC)
            ->setUseAutoGeneration(1)
            ->setUsesPerCustomer(0)
            ->setUsesPerCoupon(1)
            ->setCustomerGroupIds($customerGroupIds)
            ->setIsActive(1)
            ->setStopRulesProcessing(0)
            ->setIsAdvanced(1)
            ->setSortOrder(0)
            ->setDiscountQty(1)
            ->setDiscountStep(0)
            ->setWebsiteIds($websiteIds);

        call_user_func($handlers[$upsell['upsellType']], $upsell, $rule);

        $rule->save();

        return $rule;
    }

    public function createCoupon(Mage_SalesRule_Model_Rule $rule, $upsell)
    {
        # Check if the rule's really exists
        if (!$rule || !$rule->getId()) {
            return $this;
        }

        $couponCode = $upsell['couponCode'];
        $createdAt = $upsell['createdAt'];
        $expiresAt = $upsell['expiresAt'];

        if ($couponCode && $createdAt && $expiresAt) {

            /** @var $coupon Mage_SalesRule_Model_Coupon */
            $coupon = Mage::getModel('salesrule/coupon');
            $coupon
                ->setId(null)
                ->setRuleId($rule->getId())
                ->setUsageLimit(1)
                ->setUsagePerCustomer(1)
                ->setExpirationDate($expiresAt)
                ->setCreatedAt($createdAt)
                ->setType(Mage_SalesRule_Helper_Coupon::COUPON_TYPE_SPECIFIC_AUTOGENERATED)
                ->setCode($couponCode)
                ->save();
        }

        return $this;
    }


}
