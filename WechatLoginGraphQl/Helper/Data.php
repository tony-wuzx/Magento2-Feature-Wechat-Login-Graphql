<?php

declare(strict_types=1);

namespace Zhixing\WechatLoginGraphQl\Helper;

/**
 * Class Data
 *
 * @package Zhixing\WechatLoginGraphQl\Helper
 */
class Data
{
    /**
     * wechat api url
     */
    const WECHAT_SESSION_API_URL = 'https://api.weixin.qq.com/sns/jscode2session';

    /**
     * @var \Zhixing\WechatLogin\Helper\Data
     */
    protected $wechatHelper;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Json
     */
    protected $jsonSerializer;

    /**
     * @var \Magento\Framework\HTTP\Adapter\CurlFactory
     */
    protected $curlFactory;

    /**
     * @var \Zhixing\WechatLogin\Model\DebugFactory
     */
    protected $debugFactory;

    /**
     * @var \Zhixing\WechatLogin\Model\ResourceModel\Debug
     */
    protected $debugResource;

    /**
     * @var \Zhixing\WechatNotification\Helper\Data
     */
    protected $wechatNotificationHelper;

    /**
     * Data constructor.
     *
     * @param \Zhixing\WechatLogin\Helper\Data $wechatHelper
     * @param \Magento\Framework\Serialize\Serializer\Json $jsonSerializer
     * @param \Magento\Framework\HTTP\Adapter\CurlFactory $curlFactory
     * @param \Zhixing\WechatLogin\Model\DebugFactory $debugFactory
     * @param \Zhixing\WechatLogin\Model\ResourceModel\Debug $debugResource
     * @param \Zhixing\WechatNotification\Helper\Data $wechatNotificationHelper
     */
    public function __construct(
        \Zhixing\WechatLogin\Helper\Data $wechatHelper,
        \Magento\Framework\Serialize\Serializer\Json $jsonSerializer,
        \Magento\Framework\HTTP\Adapter\CurlFactory $curlFactory,
        \Zhixing\WechatLogin\Model\DebugFactory $debugFactory,
        \Zhixing\WechatLogin\Model\ResourceModel\Debug $debugResource,
        \Zhixing\WechatNotification\Helper\Data $wechatNotificationHelper
    ) {
        $this->wechatHelper = $wechatHelper;
        $this->jsonSerializer = $jsonSerializer;
        $this->curlFactory = $curlFactory;
        $this->debugFactory = $debugFactory;
        $this->debugResource = $debugResource;
        $this->wechatNotificationHelper = $wechatNotificationHelper;
    }

    /**
     * @param $code
     * @return bool|mixed
     */
    public function getSessionKey($code)
    {
        $params = [
            'appid' => $this->wechatNotificationHelper->getMiniprogramWechatAppId(),
            'secret' => $this->wechatNotificationHelper->getMiniprogramWechatAppSecret(),
            'js_code' => $code,
            'grant_type' => 'authorization_code',
        ];

        $returnBody = $this->httpRequest(self::WECHAT_SESSION_API_URL, http_build_query($params));
        return $this->jsonSerializer->unserialize($returnBody);
    }

    /**
     * @param $url
     * @param null $data
     * @return bool|false|string
     */
    protected function httpRequest($url, $data = null)
    {
        if (function_exists('curl_init')) {
            $curlRequest = $this->curlFactory->create();

            $curlRequest->addOption(CURLOPT_FOLLOWLOCATION, true);
            $curlRequest->addOption(CURLOPT_HEADER, 0);
            $curlRequest->addOption(CURLOPT_TIMEOUT, 30);
            $curlRequest->addOption(CURLOPT_RETURNTRANSFER, true);
            $curlRequest->addOption(CURLOPT_SSL_VERIFYPEER, false);
            $curlRequest->addOption(CURLOPT_SSL_VERIFYHOST, false);
            if (!empty($data)) {
                $curlRequest->addOption(CURLOPT_POST, 1);
                $curlRequest->addOption(CURLOPT_POSTFIELDS, $data);
            }

            $curlRequest->write(
                'POST',
                $url,
                '1.1',
                [],
                $data
            );

            $response = $curlRequest->read();
            $curlRequest->close();
            if (false === $response) {
                return false;
            } else {
                $response = \Zend_Http_Response::fromString($response);

                if ($this->wechatHelper->isDebugEnabled()) {
                    /** @var \Zhixing\WechatLogin\Model\Debug $debug */
                    $debug = $this->debugFactory->create();
                    $debug->setRequestUrl($url)
                        ->setAction(__FUNCTION__)
                        ->setRequestMethod('POST')
                        ->setRemark('Get session from code')
                        ->setRequestContent($data)
                        ->setResponseContent($response->getBody())
                        ->setOAuthType('applet');
                    try {
                        $this->debugResource->save($debug);
                    } catch (\Exception $e) {
                        unset($e);
                    }
                }

                return $response->getBody();
            }
        } elseif (function_exists('file_get_contents')) {
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            return file_get_contents($url . $data);
        } else {
            return false;
        }
    }

    /**
     * @param $encryptedData
     * @param $iv
     * @param $sessionKey
     * @return false|mixed
     */
    public function decryptData($encryptedData, $iv, $sessionKey)
    {
        if (strlen($sessionKey) != 24) {
            return false;
        }
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $aesKey = base64_decode($sessionKey);

        if (strlen($iv) != 24) {
            return false;
        }

        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $aesIV = base64_decode($iv);
        // phpcs:ignore Magento2.Functions.DiscouragedFunction
        $aesCipher = base64_decode($encryptedData);

        $result = openssl_decrypt($aesCipher, "AES-128-CBC", $aesKey, 1, $aesIV);

        $dataObj = json_decode($result);
        if ($dataObj == null) {
            return false;
        }
        if ($dataObj->watermark->appid != $this->wechatNotificationHelper->getMiniprogramWechatAppId()) {
            return false;
        }

        return json_decode($result, true);
    }
}
