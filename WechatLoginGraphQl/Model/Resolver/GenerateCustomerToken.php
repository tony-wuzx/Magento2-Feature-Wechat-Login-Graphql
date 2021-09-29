<?php

declare(strict_types=1);

namespace Zhixing\WechatLoginGraphQl\Model\Resolver;

/**
 * Class GenerateCustomerToken
 *
 * @package Zhixing\WechatLoginGraphQl\Model\Resolver
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 * @SuppressWarnings(PHPMD.TooManyFields)
 */
class GenerateCustomerToken implements \Magento\Framework\GraphQl\Query\ResolverInterface
{
    /**
     * @var \Zhixing\Config\Helper\Data
     */
    protected $configHelper;

    /**
     * @var \Zhixing\WechatLogin\Model\ResourceModel\Login\CollectionFactory
     */
    protected $loginCollectionFactory;

    /**
     * @var \Magento\Customer\Api\CustomerRepositoryInterface
     */
    protected $customerRepository;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Magento\Customer\Api\Data\CustomerInterfaceFactory
     */
    protected $customerDataFactory;

    /**
     * @var \Zhixing\PhoneSignIn\Helper\Data
     */
    protected $phoneSignInHelper;

    /**
     * @var \Zhixing\WechatLogin\Model\LoginFactory
     */
    protected $loginFactory;

    /**
     * @var \Magento\Framework\Math\Random
     */
    protected $mathRandom;

    /**
     * @var \Zhixing\WechatLogin\Model\ResourceModel\Login
     */
    protected $loginResource;

    /**
     * @var \Magento\Integration\Model\Oauth\TokenFactory
     */
    protected $tokenModelFactory;

    /**
     * @var \Magento\Framework\Event\ManagerInterface
     */
    protected $eventManager;

    /**
     * @var \Zhixing\WechatLoginGraphQl\Helper\Data
     */
    protected $wechatLoginHelper;

    /**
     * @var \Zhixing\PhoneSignIn\Api\SignInInterface
     */
    protected $signIn;

    /**
     * GenerateCustomerToken constructor.
     *
     * @param \Zhixing\Config\Helper\Data $configHelper
     * @param \Zhixing\WechatLogin\Model\ResourceModel\Login\CollectionFactory $loginCollectionFactory
     * @param \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Magento\Customer\Api\Data\CustomerInterfaceFactory $customerDataFactory
     * @param \Zhixing\PhoneSignIn\Helper\Data $phoneSignInHelper
     * @param \Zhixing\WechatLogin\Model\LoginFactory $loginModel
     * @param \Magento\Framework\Math\Random $mathRandom
     * @param \Zhixing\WechatLogin\Model\ResourceModel\Login $loginResource
     * @param \Magento\Integration\Model\Oauth\TokenFactory $tokenFactory
     * @param \Magento\Framework\Event\ManagerInterface $eventManager
     * @param \Zhixing\WechatLoginGraphQl\Helper\Data $wechatLoginHelper
     * @param \Zhixing\PhoneSignIn\Api\SignInInterface $signIn
     */
    public function __construct(
        \Zhixing\Config\Helper\Data $configHelper,
        \Zhixing\WechatLogin\Model\ResourceModel\Login\CollectionFactory $loginCollectionFactory,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Customer\Api\Data\CustomerInterfaceFactory $customerDataFactory,
        \Zhixing\PhoneSignIn\Helper\Data $phoneSignInHelper,
        \Zhixing\WechatLogin\Model\LoginFactory $loginModel,
        \Magento\Framework\Math\Random $mathRandom,
        \Zhixing\WechatLogin\Model\ResourceModel\Login $loginResource,
        \Magento\Integration\Model\Oauth\TokenFactory $tokenFactory,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Zhixing\WechatLoginGraphQl\Helper\Data $wechatLoginHelper,
        \Zhixing\PhoneSignIn\Api\SignInInterface $signIn
    ) {
        $this->configHelper = $configHelper;
        $this->loginCollectionFactory = $loginCollectionFactory;
        $this->customerRepository = $customerRepository;
        $this->storeManager = $storeManager;
        $this->customerDataFactory = $customerDataFactory;
        $this->phoneSignInHelper = $phoneSignInHelper;
        $this->loginFactory = $loginModel;
        $this->mathRandom = $mathRandom;
        $this->loginResource = $loginResource;
        $this->tokenModelFactory = $tokenFactory;
        $this->eventManager = $eventManager;
        $this->wechatLoginHelper = $wechatLoginHelper;
        $this->signIn = $signIn;
    }

    /**
     * @inheritdoc
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     */
    public function resolve(
        \Magento\Framework\GraphQl\Config\Element\Field $field,
        $context,
        \Magento\Framework\GraphQl\Schema\Type\ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        $result = ['token' => ''];
        if (!$this->configHelper->isChineseInstance()
            || !$this->configHelper->isChinaMiniProgramStore(
                (int) $context->getExtensionAttributes()->getStore()->getId()
            )
        ) {
            return $result;
        }

        if (empty($args['code'])) {
            return $result;
        } else {
            $data = $this->wechatLoginHelper->getSessionKey($args['code']);
        }

        if ($data) {
            $openId = $data['openid'] ?? null;
            $unionId = $data['unionid'] ?? null;
            $sessionKey = $data['session_key'] ?? null;
        }

        if (empty($openId)) {
            return $result;
        }

        $phoneNumber = null;
        if (!empty($args['encrypted_data']) && !empty($args['iv']) && !empty($sessionKey)) {
            $encryptedData = $this->wechatLoginHelper->decryptData($args['encrypted_data'], $args['iv'], $sessionKey);
            if (!empty($encryptedData['purePhoneNumber'])) {
                $phoneNumber = $encryptedData['purePhoneNumber'];
            }
        }

        try {
            /** @var \Zhixing\WechatLogin\Model\Login $socialLogin */
            $socialLogin = $this->loginCollectionFactory->create()
                ->addFieldToFilter('type', 'applet')
                ->addFieldToFilter('openid', $openId)
                ->getFirstItem();

            if (!$socialLogin->getId()) {
                $oldCustomerId = null;
                if (!empty($unionId)) {
                    $oldSocialLogin = $this->loginCollectionFactory->create()
                        ->addFieldToFilter('type', 'applet')
                        ->addFieldToFilter('unionid', $unionId)
                        ->getFirstItem();
                    if ($oldSocialLogin && $oldSocialLogin->getId()) {
                        $oldCustomerId = $oldSocialLogin->getCustomerId();
                    }
                }

                if (empty($oldCustomerId)
                    && !empty($phoneNumber)
                    && $this->phoneSignInHelper->isValidPhoneNumber($phoneNumber)
                ) {
                    if ($oldCustomer = $this->signIn->getByPhoneNumber($phoneNumber)) {
                        $oldCustomerId = $oldCustomer->getId();
                    } else {
                        $oldCustomerId = $this->processCustomerCreation($phoneNumber);
                    }
                }

                if (empty($oldCustomerId)) {
                    return $result;
                }

                /** @var \Zhixing\WechatLogin\Model\Login $socialLogin */
                $socialLogin = $this->loginFactory->create();
                $socialLogin->setCustomerId($oldCustomerId);
                $socialLogin->setType('applet');
                $socialLogin->setStatus(true);
                $socialLogin->setWebsiteId($this->configHelper->getMiniprogramWebsiteId());
                $socialLogin->setUnionid($unionId);
                $socialLogin->setOpenid($openId);
                $this->loginResource->save($socialLogin);

            } else {
                if (!empty($unionId) && $unionId != $socialLogin->getUnionid()) {
                    $socialLogin->setUnionid($unionId);
                    $this->loginResource->save($socialLogin);
                }
            }

            /**@var $currentCustomer \Magento\Customer\Model\Customer */
            $currentCustomerId = (int) $socialLogin->getCustomerId();
            $currentCustomer = $this->customerRepository->getById($currentCustomerId);

            if ($currentCustomer && $currentCustomer->getId()) {
                //merge quote viewed wishlist
                $this->eventManager->dispatch('customer_login_after', ['customer' => $currentCustomer]);

                $token = $this->tokenModelFactory->create()
                    ->createCustomerToken($currentCustomer->getId())
                    ->getToken();

                return ['token' => $token];
            }

        } catch (\Exception $e) {
            throw new \Magento\Framework\GraphQl\Exception\GraphQlAuthenticationException(__($e->getMessage()), $e);
        }

        return $result;
    }

    /**
     * Create new applet customer
     *
     * @param string $phoneNumber
     * @return int|null
     * @throws \Magento\Framework\Exception\InputException
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     * @throws \Magento\Framework\Exception\State\InputMismatchException
     */
    protected function processCustomerCreation($phoneNumber): ?int
    {
        $storeId = $this->configHelper->getMiniprogramStoreId();

        /** @var \Magento\Customer\Api\Data\CustomerInterface $customer */
        $customer = $this->customerDataFactory->create();
        $customer->setPrefix('Mr'); // default value (Male)
        $customer->setCustomAttribute('country', 'CN');
        $customer->setWebsiteId($this->configHelper->getMiniprogramWebsiteId());
        $str = substr(sha1(time() . $phoneNumber), 8, 16);
        $customer->setEmail($str . '@' . $this->phoneSignInHelper->getFakeEmailDomain($storeId));
        $customer->setFirstname((string) $phoneNumber); // we don't have authorization to get Wechat Username
        $customer->setLastname((string) $phoneNumber); // we don't have authorization to get Wechat Username
        $customer->setGender(1); // default value (Male)
        $customer->setConfirmation($this->mathRandom->getRandomString(32));
        $customer->setAddresses(null);
        $customer->setStoreId($storeId);
        $storeName = $this->storeManager->getStore($storeId)->getName();
        $customer->setCreatedIn($storeName);
        $customer->setCustomAttribute(
            \Zhixing\WechatLogin\Setup\Patch\Data\InstallCustomerFlag::CUSTOMER_SOCIAL_LOGIN_FLAG,
            true
        );

        if (!empty($phoneNumber) && $this->phoneSignInHelper->isValidPhoneNumber($phoneNumber)) {
            $customer->setCustomAttribute('phone_number', $phoneNumber);
        }

        $customer->setData('ignore_validation_flag', true);

        $customer = $this->customerRepository->save($customer);

        $this->eventManager->dispatch('customer_register_success_cron', ['customer' => $customer]);

        return (int) $customer->getId();
    }
}
