<?php
/**
 * This file is part of the Receiptful extension.
 *
 * (c) Receiptful <info@receiptful.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Stefano Sala <stefano@receiptful.com>
 */
class Receiptful_Core_Observer_Tracking
{
    /**
     * Set up tracking script conversion
     *
     * @param  Varien_Event_Observer $observer
     */
    public function addTrackingConversion(Varien_Event_Observer $observer)
    {
        $block = Mage::app()->getLayout()->getBlock('head');

        $block->removeExternalItem('js_text', 'Receiptful.setTrackingCookie();');

        $orderIds = $observer->getOrderIds();

        if (0 === count($orderIds)) {
            return;
        }

        $order = Mage::getModel('sales/order')->load($orderIds[0]);

        $block->addItem('js_text', sprintf('Receiptful.conversion.reference = "%s";', $order->getIncrementId()));
        $block->addItem('js_text', sprintf('Receiptful.conversion.amount = %s;', $order->getGrandTotal()));
        $block->addItem('js_text', sprintf('Receiptful.conversion.currency = "%s";', $order->getOrderCurrencyCode()));

        if ($order->getCouponCode()) {
            $block->addItem('js_text', sprintf('Receiptful.conversion.couponCode = "%s";', $order->getCouponCode()));
        }

        $block->addItem('js_text', 'Receiptful.trackConversion();');
    }
}
