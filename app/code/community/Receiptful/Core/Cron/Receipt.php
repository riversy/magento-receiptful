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
class Receiptful_Core_Cron_Receipt
{
    /**
     * Resend receipts marked as failed.
     */
    public function resendFailures()
    {
        $resource = Mage::getSingleton('core/resource');

        $readConnection = $resource->getConnection('core_read');

        $query = 'SELECT * FROM ' . $resource->getTableName('sales/invoice') .
            ' WHERE receiptful_receipt_failed_at IS NOT NULL' .
            ' AND receiptful_id is NULL';

        $results = $readConnection->fetchAll($query);

        Mage::Log(sprintf('Running resend on %s receipts.', $results));

        $receiptObserver = new Receiptful_Core_Observer_Receipt();

        foreach ($results as $invoiceData) {
            Mage::Log(sprintf('Running send on ' . $invoiceData['entity_id']));

            $invoice = Mage::getModel('sales/order_invoice')->load($invoiceData['entity_id']);

            try {
                $receiptObserver->createInvoiceReceipt($invoice);

                Mage::Log('Done');
            } catch (Exception $e) {
                Mage::Log('Got exception: ' . $e->getMessage());
            }

            $invoice->save();
        }
    }
}
