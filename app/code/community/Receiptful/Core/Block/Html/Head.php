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
class Receiptful_Core_Block_Html_Head extends Mage_Page_Block_Html_Head
{
    /**
     * {@inheritdoc}
     */
    protected function _construct()
    {
        parent::_construct();

        $this->addItem('external_js', Receiptful_Core_ApiClient::getBaseUrl() . '/scripts/tracking.js');
        $this->addItem('js_text', 'Receiptful.setTrackingCookie();');
    }

    /**
     * {@inheritdoc}
     */
    protected function _separateOtherHtmlHeadElements(&$lines, $itemIf, $itemType, $itemParams, $itemName, $itemThe)
    {
        if ('external_js' === $itemType) {
            return $lines[$itemIf]['other'][] = sprintf('<script type="text/javascript" src="%s" %s></script>', $itemName, $params);
        }

        if ('js_text' === $itemType) {
            return $lines[$itemIf]['other'][] = sprintf('<script type="text/javascript" %s>%s</script>', $params, $itemName);
        }

        return parent::_separateOtherHtmlHeadElements($lines, $itemIf, $itemType, $itemParams, $itemName, $itemThe);
    }

    /**
     * Remove External Item from HEAD entity
     *
     * @param string $type
     * @param string $name
     * @return Mage_Page_Block_Html_Head
     */
    public function removeExternalItem($type, $name)
    {
        parent::removeItem($type, $name);
    }
}
