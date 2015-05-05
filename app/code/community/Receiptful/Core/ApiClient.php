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
class Receiptful_Core_ApiClient
{
    const RECEIPTFUL_API_KEY_CONFIGURATION = 'receiptful/configuration/api_key';

    /**
     * Send a request to Receiptful apis
     *
     * @param  array  $data Array of POST data
     * @param  string $url  Last part of the request url
     *
     * @return array        The response body decoded
     *
     * @throws Receiptful_Core_Exception_FailedRequestException If the request is unsuccessful
     */
    public static function sendRequest(array $data, $url)
    {
        $apiKey = static::getApiKey();

        // If the module has not been configured yet, skip everything
        if (!$apiKey) {
            throw new Receiptful_Core_Exception_FailedRequestException(401, '401: your api key seems not correct, please check it.');
        }

        $encodedData = json_encode($data);

        $ch = curl_init(static::getUrl() . $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($encodedData),
            'X-ApiKey: ' . $apiKey
        ));

        $result = curl_exec($ch);

        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        curl_close($ch);

        if (in_array($httpCode, array(200, 201))) {
            return json_decode($result, true);
        }

        if (400 === $httpCode) {
            $result = json_decode($result, true);

            throw new Receiptful_Core_Exception_FailedRequestException(400, $httpCode . ': ' . implode(', ', $result));
        }

        if (401 === $httpCode) {
            throw new Receiptful_Core_Exception_FailedRequestException(401, $httpCode . ': your api key seems not correct, please check it.');
        }

        throw new Receiptful_Core_Exception_FailedRequestException($httpCode, $httpCode . ': an unexpected exception has occurred.');
    }

    public static function getBaseUrl()
    {
        $apiKey = Mage::getStoreConfig(self::RECEIPTFUL_API_KEY_CONFIGURATION);

        if (preg_match('/staging/', $apiKey)) {
            return 'http://staging.receiptful.com';
        }

        if (preg_match('/localhost/', $apiKey)) {
            return 'http://localhost:9000';
        }

        return 'https://app.receiptful.com';
    }

    private static function getUrl()
    {
        return static::getBaseUrl() . '/api/v1';
    }

    private static function getApiKey()
    {
        $apiKey = Mage::getStoreConfig(self::RECEIPTFUL_API_KEY_CONFIGURATION);

        return str_replace('staging-', '', str_replace('localhost-', '', $apiKey));
    }
}
