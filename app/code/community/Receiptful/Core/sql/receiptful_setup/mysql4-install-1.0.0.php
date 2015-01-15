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
$installer = $this;
$installer->startSetup();

$installer
    ->getConnection()
    ->addColumn(
        $installer->getTable('sales/invoice'),
        'receiptful_id',
        array(
            'type' => Varien_Db_Ddl_Table::TYPE_TEXT,
            'nullable' => true,
            'default' => null,
            'comment' => 'Receiptful Id'
        )
    );

$installer->endSetup();
