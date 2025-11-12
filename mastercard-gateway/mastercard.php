<?php
/**
 * Copyright (c) 2019-2026 Mastercard
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 * @package  Mastercard
 * @version  GIT: @1.4.5@
 * @link     https://github.com/fingent-corp/gateway-prestashop-mastercard-module
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;
use Fingent\Mastercard\Gateway\GatewayService;
use Fingent\Mastercard\Handlers\ResponseProcessor;
use Fingent\Mastercard\Handlers\TransactionResponseHandler;
use Fingent\Mastercard\Handlers\TransactionStatusResponseHandler;
use Fingent\Mastercard\Handlers\MasterCardPaymentException;
use Fingent\Mastercard\Api\ApiErrorPlugin;
use Fingent\Mastercard\Model\MpgsRefund;
use Fingent\Mastercard\Model\MpgsVoid;
use Fingent\Mastercard\Service\MpgsRefundService;

if (!defined('_PS_VERSION_')) {
    throw new MasterCardPaymentException('Direct access not allowed.');
}

if (!defined('MPGS_ISO3_COUNTRIES')) {
    define('MPGS_ISO3_COUNTRIES', include_once dirname(__FILE__).'/../iso3.php');
}

/**
 * @property bool bootstrap
 */
class Mastercard extends PaymentModule
{
    const PAYMENT_CODE                       = 'MG';
    const MPGS_API_VERSION                   = '100';
    const MPGS_3DS_LIB_VERSION               = '1.3.0';
    const PAYMENT_CHECKOUT_SESSION_PURCHASE  = 'PURCHASE';
    const PAYMENT_CHECKOUT_SESSION_AUTHORIZE = 'AUTHORIZE';
    const PAYMENT_CHECKOUT_EMBEDDED_METHOD   = 'EMBEDDED';
    const PAYMENT_CHECKOUT_REDIRECT_METHOD   = 'REDIRECT';
    const ENTERPRISE_MODULE_KEY              = '3cfa292619f39b06479454445cd1c7668bd6ad752b74e78af280f428eeff5226';
    const MPGS_API_URL                       = 'https://mpgs.fingent.wiki/wp-json/mpgs/v2/update-repo-status';
    const COLOR                              = '#4169E1';
    const TENGIF                             = '/img/os/10.gif';
    const IMG                                = '/img/os/';
    const HTTPS_PREFIX                       = 'https://';

    /**
     * @var string
     */
    protected $htmlContent = '';

    /**
     * @var string
     */
    protected $controllerAdmin;

    /**
     * @var array
     */
    protected $postErrors = array();

    /**
     * Mastercard constructor.
     */
    public function __construct()
    {
        $this->module_key = '5e026a47ceedc301311e969c872f8d41';
        $this->name       = 'mastercard';
        $this->tab        = 'payments_gateways';
        $this->version    = '1.4.5';
        if (!defined('MPGS_VERSION')) {
            define('MPGS_VERSION', $this->version);
        }
        $this->author                 = 'MasterCard';
        $this->need_instance          = 1;
        $this->controllers            = array('payment', 'validation');
        $this->ps_versions_compliancy = array('min' => '1.7.1.0', 'max' => _PS_VERSION_);
        $this->currencies             = true;
        $this->currencies_mode        = 'checkbox';

        /**
         * Set $this->bootstrap to true if your module is compliant with bootstrap (PrestaShop 1.6)
         */
        $this->bootstrap        = true;
        parent::__construct();
        $this->controllerAdmin  = 'AdminMpgs';
        $this->displayName      = $this->l('Mastercard Gateway');
        $this->description      = $this->l('Mastercard Gateway module for Prestashop');
    }

    /**
     * @param string $iso2country
     *
     * @return string
     */
    public function iso2ToIso3($iso2country)
    {
        return MPGS_ISO3_COUNTRIES[$iso2country];
    }

    /**
     * @return string
     */
    public static function getApiVersion()
    {
        return self::MPGS_API_VERSION;
    }

    /**
     * @return string
     */
    public static function get3DSLibVersion()
    {
        return self::MPGS_3DS_LIB_VERSION;
    }

    /**
     * Don't forget to create update methods if needed:
     * http://doc.prestashop.com/display/PS16/Enabling+the+Auto-Update
     * @throws Exception
     */
    public function install()
    {
        if (!extension_loaded('curl')) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');
            return false;
        }

        if (
            !$this->isInstallOrderState() ||
            !$this->installTab()
        ) {
            return false;
        }

        $parentInstalled    =   parent::install();
        $hooksRegistered    =   $this->registerHook('paymentOptions') &&
                                $this->registerHook('displayAdminOrderLeft') &&
                                $this->registerHook('displayAdminOrderSideBottom');

        $actionRegistered   =   $this->registerHook('actionObjectOrderSlipAddAfter') &&
                                $this->registerHook('displayBackOfficeHeader') ;

        $upgradesSuccessful =   $this->isUpgradeModuleRefund() &&
                                $this->isUpgradeModuleDrop() &&
                                $this->isUpgradeModuleVoid();

        return $parentInstalled && $hooksRegistered && $actionRegistered && $upgradesSuccessful;
    }

    /**
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function isUninstall()
    {
        Configuration::deleteByName('mpgs_hc_title');
        $this->unregisterHook('paymentOptions');
        $this->unregisterHook('displayAdminOrderLeft');
        $this->unregisterHook('displayAdminOrderSideBottom');
        $this->unregisterHook('actionObjectOrderSlipAddAfter');
        $this->unregisterHook('displayBackOfficeHeader');
        $this->isUninstallTab();
        return parent::isUninstall();
    }
    
    /**
     * @param $params
     */
    public function hookDisplayBackOfficeHeader()
    {
        if (!$this->active) {
            return;
        }

        $this->context->controller->addCSS($this->_path.'views/css/style.css', 'all');
    }

    /**
     * @return int
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function installTab()
    {
        $tab             = new Tab();
        $tab->class_name = $this->controllerAdmin;
        $tab->active     = 1;
        $tab->name       = array();
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $this->name;
        }
        $tab->id_parent = -1;
        $tab->module    = $this->name;

        return $tab->add();
    }

    /**
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function isUninstallTab()
    {
        $idTab = (int)Tab::getIdFromClassName($this->controllerAdmin);
        $tab   = new Tab($idTab);
        if (Validate::isLoadedObject($tab)) {
            return $tab->delete();
        }

        return true;
    }

    /**
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function isInstallOrderState()
    {
        $this->createOrderStateIfNotExists('MPGS_OS_PAYMENT_WAITING', 'Awaiting Payment', [
            'send_email' => false,
            'color'      => self::COLOR,
            'hidden'     => false,
            'delivery'   => false,
            'logable'    => false,
            'invoice'    => false,
            'paid'       => false,
            'template'   => null,
            'gif'        => _PS_ROOT_DIR_ . self::TENGIF,
        ]);

        $this->createOrderStateIfNotExists('MPGS_OS_AUTHORIZED', 'Payment Authorized', [
            'send_email' => true,
            'color'      => self::COLOR,
            'hidden'     => false,
            'delivery'   => false,
            'logable'    => true,
            'invoice'    => false,
            'paid'       => true,
            'template'   => 'payment',
            'gif'        => _PS_ROOT_DIR_ . self::TENGIF,
        ]);

        $this->createOrderStateIfNotExists('MPGS_OS_REVIEW_REQUIRED', 'Payment Review Required', [
            'send_email' => false,
            'color'      => self::COLOR,
            'hidden'     => false,
            'delivery'   => false,
            'logable'    => false,
            'invoice'    => false,
            'paid'       => false,
            'template'   => null,
            'gif'        => _PS_ROOT_DIR_ . self::TENGIF,
        ]);

        $this->createOrderStateIfNotExists('MPGS_OS_FRAUD', 'Suspected Fraud', [
            'send_email' => false,
            'color'      => '#DC143C',
            'hidden'     => false,
            'delivery'   => false,
            'logable'    => false,
            'invoice'    => false,
            'paid'       => false,
            'template'   => null,
            'gif'        => _PS_ROOT_DIR_ . '/img/os/6.gif',
        ]);

        $this->createOrderStateIfNotExists('MPGS_OS_PARTIALLY_REFUNDED', 'Partially Refunded', [
            'send_email' => true,
            'color'      => '#01B887',
            'hidden'     => false,
            'delivery'   => false,
            'logable'    => true,
            'invoice'    => false,
            'paid'       => true,
            'template'   => 'refund',
            'gif'        => _PS_ROOT_DIR_ . '/img/os/7.gif',
        ]);

        return true;
    }

    /*
    *
    * Create order state if not exit
    */
    private function createOrderStateIfNotExists(string $configKey, string $defaultName, array $options)
    {
        $stateId       = Configuration::get($configKey);
        $existingState = new OrderState($stateId);

        if (!$stateId || !Validate::isLoadedObject($existingState)) {
            $orderState = new OrderState();
            foreach (Language::getLanguages() as $language) {
                $orderState->name[$language['id_lang']] = $defaultName;
                if (!empty($options['template'])) {
                    $orderState->template[$language['id_lang']] = $options['template'];
                }
            }

            $orderState->send_email = $options['send_email'];
            $orderState->color      = $options['color'];
            $orderState->hidden     = $options['hidden'];
            $orderState->delivery   = $options['delivery'];
            $orderState->logable    = $options['logable'];
            $orderState->invoice    = $options['invoice'];
            $orderState->paid       = $options['paid'];

            if ($orderState->add()) {
                $destination = _PS_ROOT_DIR_ . self::IMG . (int)$orderState->id . '.gif';
                copy($options['gif'], $destination);
                Configuration::updateValue($configKey, (int)$orderState->id);
            }
        }
    }

    /*
    * Check the new release in the github
    *
    */
    public function checkForUpdates()
    {
        // Get the latest release information from GitHub
        $latestRelease = $this->getLatestGitHubVersion();

        // Compare the latest release version with the current module version
        if ($latestRelease !== null && version_compare($latestRelease['version'], $this->version, '>')) {
            // Newer version available
            return [
                'available'    => true,
                'version'      => $latestRelease['version'],
                'download_url' => $latestRelease['download_url']
            ];
        } else {
            // Module is up to date
            return [
                'available' => false,
                'version'   => $this->version
            ];
        }
    }

    /*
    *
    *return the new github version and link
    */
    private function getLatestGitHubVersion() {
        $owner = 'fingent-corp';
        $repo  = 'gateway-prestashop-mastercard-module';
        $url   = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
        $ch    = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mastercard');
        $response = curl_exec($ch);
        if (curl_errno($ch)) {
            return null;
        }
        curl_close($ch);
        $data = json_decode($response, true);
        
        if (isset($data['tag_name']) && isset($data['assets'][0]['browser_download_url'])) {
            return [
                'version'      => $data['tag_name'],
                'download_url' => $data['assets'][0]['browser_download_url']
            ];
        } else {
            return null;
        }
    }


    /**
     * @return string
     * @throws PrestaShopException
     */
    public function getContent()
    {
        // Initialize htmlContent variable
        $this->htmlContent = '';

        // Process form submission
        if (Tools::isSubmit('submitMastercardModule')) {
            $this->_postValidation();
            if (!count($this->postErrors)) {
                $this->postProcess();
            } else {
                foreach ($this->postErrors as $err) {
                    $this->htmlContent .= $this->displayError($err);
                }
            }
        }

        // Add JavaScript file
        $this->context->controller->addJS($this->_path.'/views/js/back.js');
        $this->context->controller->addJS($this->_path.'/views/js/admin.js');
        // Assign variables for templates
        $this->context->smarty->assign([
            'module_dir'             => $this->_path,
            'mpgs_gateway_validated' => Configuration::get('mpgs_gateway_validated'),
        ]);

        // Fetch latest release information
        $latestRelease = $this->checkForUpdates();
        // Get the module version
        $moduleVersion = $this->version;
        $this->context->smarty->assign([
            'latest_release' => $latestRelease,
            'module_version' => $moduleVersion,
        ]);

        // Render notification template
        $notificationContent = $this->display($this->local_path, 'views/templates/admin/update.tpl');

        // Append notification content to htmlContent
        $this->htmlContent .= $notificationContent;

        // Render form success or error messages
        if (isset($this->htmlContentSuccess)) {
            $this->htmlContent .= $this->displayConfirmation($this->htmlContentSuccess);
        }
        if (isset($this->htmlContentWarning)) {
            $this->htmlContent .= $this->displayWarning($this->htmlContentWarning);
        }
        if (isset($this->htmlContentError)) {
            $this->htmlContent .= $this->displayError($this->htmlContentError);
        }

        // Render main configuration template
        $mainContent = $this->display($this->local_path, 'views/templates/admin/configure.tpl');

        // Append main content to htmlContent
        $this->htmlContent .= $mainContent;

        // Render form
        $this->htmlContent .= $this->renderForm();

        // Return the final content
        return $this->htmlContent;
    }

    /**
    * Perform post-submission validation and handle file upload.
    * @return void
    */
    protected function _postValidation()
    {
        $this->validateApiUrl();
        $this->validateMerchantId();
        $this->validateFields();
        $this->handleLogoUpload();
    }

    /**
    * Validate that at least one API URL (default or custom) is provided.
    *
    * @return void
    */
    private function validateApiUrl()
    {
        if (!Tools::getValue('mpgs_api_url') && !Tools::getValue('mpgs_api_url_custom')) {
            $this->postErrors[] = $this->l('Custom API Endpoint is required.');
        }
    }

    /**
    * Validate that the selected mode (Live or Test).
    *
    * @return value
    */
    private function isLiveMode()
    {
        return Tools::getValue('mpgs_mode') === "1";
    }

    /**
    * Validate that the correct Merchant ID is provided based on the selected mode (Live or Test).
    *
    * @return void
    */
    private function validateMerchantId()
    {
        $isLive = $this->isLiveMode();
        $field  = $isLive ? 'mpgs_merchant_id' : 'test_mpgs_merchant_id';
        $label  = $isLive ? 'Merchant ID' : 'Test Merchant ID';

        if (!Tools::getValue($field)) {
            $this->postErrors[] = $this->l("$label is required.");
        }
    }

    /**
    * Validate multiple merchant info fields for length, format, and data type.
    *
    * @return void
    */
    private function validateFields()
    {
        $validations = [
            'mpgs_mi_merchant_name' => ['max' => 40],
            'mpgs_mi_address_line1' => ['max' => 100],
            'mpgs_mi_address_line2' => ['max' => 100],
            'mpgs_mi_postalcode'    => ['max' => 100],
            'mpgs_mi_country'       => ['max' => 100],
            'mpgs_mi_email'         => ['email' => true],
            'mpgs_mi_phone'         => ['max' => 20],
        ];

        foreach ($validations as $key => $rules) {
            $value = Tools::getValue($key);
            $label = ucwords(str_replace('_', ' ', $key));

            if (!empty($rules['max']) && strlen($value) > $rules['max']) {
                $this->postErrors[] = $this->l("$label must not exceed {$rules['max']} characters.");
            }

            if (!empty($rules['email']) && !empty($value) && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
                $this->postErrors[] = $this->l('Invalid email format.');
            }

            if (!empty($rules['numeric']) && !empty($value) && !preg_match('/^\d+$/', $value)) {
                $this->postErrors[] = $this->l("$label must be a valid number.");
            }
        }
    }

    /**
    * Handle the upload of the merchant logo image.
    * Validates the file type, stores the uploaded image in the module's directory,
    * and updates the configuration with the image URL.
    *
    * @return void
    */
    private function handleLogoUpload()
    {
        $fileData     = Tools::fileAttachment('mpgs_mi_logo');
        $file         = $fileData['name'] ?? null;
        $tmpName      = $fileData['tmp_name'] ?? null;
        $existingLogo = Configuration::get('mpgs_mi_logo');

        if (!empty($file)) {
            // Get file extension safely
            $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

            // Allowed extensions
            $allowedExtensions = ['jpg', 'jpeg', 'png', 'svg'];

            if (in_array($extension, $allowedExtensions)) {
                $uploadDir = _PS_MODULE_DIR_ . 'mastercard/views/upload/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $fileName = uniqid() . '_' . basename($file);
                $destination = $uploadDir . $fileName;

                // Move uploaded file (no direct $_FILES access)
                if ($tmpName && move_uploaded_file($tmpName, $destination)) {
                    $imageUrl = _PS_BASE_URL_SSL_ . __PS_BASE_URI__ . 'modules/mastercard/views/upload/' . $fileName;
                    Configuration::updateValue('mpgs_mi_logo', $imageUrl);
                } else {
                    $this->postErrors[] = $this->l('File upload failed.');
                }
            } else {
                $this->postErrors[] = $this->l('Invalid file type. Only JPG, PNG, and SVG extensions allowed.');
            }
        } elseif (!empty($existingLogo)) {
            Configuration::updateValue('mpgs_mi_logo', $existingLogo);
        } else {
            Configuration::updateValue('mpgs_mi_logo', '');
        }
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     * @throws PrestaShopException
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar             = false;
        $helper->table                    = $this->table;
        $helper->module                   = $this;
        $helper->default_form_language    = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier               = $this->identifier;
        $helper->submit_action            = 'submitMastercardModule';
        $helper->currentIndex             = $this->context->link->getAdminLink('AdminModules', false)
                                .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token                    = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars                 = array(
            'fields_value' => $this->getAdminFormValues(), /* Add values for your inputs */
            'languages'    => $this->context->controller->getLanguages(),
            'id_language'  => $this->context->language->id,
        );

        return $helper->generateForm(array(
            $this->getAdminHostedCheckoutForm(),
            $this->getMerchantInfoForm(),
            $this->getAdminGeneralSettingsForm(),
            $this->getAdminAdvancedSettingForm(),
        ));
    }

    /**
     * @return array
     */
    protected function getAdminFormValues()
    {
        $hcTitle   = array();
        $languages = Language::getLanguages(false);
        foreach ($languages as $lang) {
            $value = Tools::getValue(
                'mpgs_hc_title_'.$lang['id_lang'],
                Configuration::get('mpgs_hc_title', $lang['id_lang'])
            );
            $hcTitle[$lang['id_lang']] = $value ? $value : $this->l('MasterCard Hosted Checkout');

        }

        return array(
            'mpgs_hc_active'         => Tools::getValue('mpgs_hc_active', Configuration::get('mpgs_hc_active')),
            'mpgs_hc_title'          => $hcTitle,
            'mpgs_hc_payment_action' => Tools::getValue('mpgs_hc_payment_action',
                Configuration::get('mpgs_hc_payment_action')),
            'mpgs_hc_show_billing'   => Tools::getValue('mpgs_hc_show_billing',
                Configuration::get('mpgs_hc_show_billing') ?: 'HIDE'),
            'mpgs_hc_show_email'     => Tools::getValue('mpgs_hc_show_email',
                Configuration::get('mpgs_hc_show_email') ?: 'HIDE'),
            'mpgs_mode'              => Tools::getValue('mpgs_mode', Configuration::get('mpgs_mode')),
            'mpgs_order_prefix'      => Tools::getValue('mpgs_order_prefix', Configuration::get('mpgs_order_prefix')),
            'mpgs_api_url'           => Tools::getValue('mpgs_api_url', Configuration::get('mpgs_api_url')),
            'mpgs_api_url_custom'    => Configuration::get('mpgs_api_url_custom'),
            'mpgs_lineitems_enabled' => Tools::getValue('mpgs_lineitems_enabled',
                Configuration::get('mpgs_lineitems_enabled') ?: "1"),
            'mpgs_webhook_url'       => Tools::getValue('mpgs_webhook_url', Configuration::get('mpgs_webhook_url')),
            'mpgs_logging_level'     => Tools::getValue('mpgs_logging_level',
                Configuration::get('mpgs_logging_level') ?: \Monolog\Logger::ERROR),

            'mpgs_merchant_id'       => Tools::getValue('mpgs_merchant_id', Configuration::get('mpgs_merchant_id')),
            'mpgs_api_password'      => Tools::getValue('mpgs_api_password', Configuration::get('mpgs_api_password')),
            'mpgs_webhook_secret'    => Tools::getValue('mpgs_webhook_secret',
                Configuration::get('mpgs_webhook_secret') ?: null),
            'mpgs_mi_active'            => Tools::getValue('mpgs_mi_active', Configuration::get('mpgs_mi_active')),
            'mpgs_mi_merchant_name'     => Tools::getValue('mpgs_mi_merchant_name', Configuration::get('mpgs_mi_merchant_name')),
            'mpgs_mi_address_line1'     => Tools::getValue('mpgs_mi_address_line1', Configuration::get('mpgs_mi_address_line1')),
            'mpgs_mi_address_line2'     => Tools::getValue('mpgs_mi_address_line2', Configuration::get('mpgs_mi_address_line2')),
            'mpgs_mi_postalcode'        => Tools::getValue('mpgs_mi_postalcode', Configuration::get('mpgs_mi_postalcode')),
            'mpgs_mi_country'           => Tools::getValue('mpgs_mi_country', Configuration::get('mpgs_mi_country')),
            'mpgs_mi_email'             => Tools::getValue('mpgs_mi_email', Configuration::get('mpgs_mi_email')),
            'mpgs_mi_phone'             => Tools::getValue('mpgs_mi_phone', Configuration::get('mpgs_mi_phone')),

            'test_mpgs_merchant_id'    => Tools::getValue('test_mpgs_merchant_id',
                Configuration::get('test_mpgs_merchant_id')),
            'test_mpgs_api_password'   => Tools::getValue('test_mpgs_api_password',
                Configuration::get('test_mpgs_api_password')),
            'test_mpgs_webhook_secret' => Tools::getValue('test_mpgs_webhook_secret',
                Configuration::get('test_mpgs_webhook_secret') ?: null),
            'mpgs_hc_payment_method'   => Tools::getValue('mpgs_hc_payment_method',
                Configuration::get('mpgs_hc_payment_method')),
        );
    }

    /**
     * @return array
     */
    protected function getAdminHostedCheckoutForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Payment Method Settings - Hosted Checkout'),
                    'icon'  => 'icon-cogs',
                ),
                'input'  => array(
                    array(
                        'type'    => 'switch',
                        'label'   => $this->l('Enable/Disable'),
                        'name'    => 'mpgs_hc_active',
                        'is_bool' => true,
                        'desc'    => '',
                        'values'  => array(
                            array(
                                'id'    => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ),
                            array(
                                'id'    => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ),
                        ),
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->l('Title'),
                        'name'     => 'mpgs_hc_title',
                        'required' => true,
                        'lang'     => true,
                    ),
                    array(
                        'type'    => 'select',
                        'label'   => $this->l('Payment Model'),
                        'name'    => 'mpgs_hc_payment_action',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id'   => self::PAYMENT_CHECKOUT_SESSION_PURCHASE,
                                    'name' => $this->l('Purchase'),
                                ),
                                array(
                                    'id'   => self::PAYMENT_CHECKOUT_SESSION_AUTHORIZE,
                                    'name' => $this->l('Authorize'),
                                ),
                            ),
                            'id'    => 'id',
                            'name'  => 'name',
                        ),
                    ),
                    array(
                        'type'    => 'select',
                        'label'   => $this->l('Checkout Interaction Model'),
                        'name'    => 'mpgs_hc_payment_method',
                        'options' => array(
                            'query' => array(
                                array(
                                    'id' => self::PAYMENT_CHECKOUT_EMBEDDED_METHOD,
                                    'name' => $this->l('Embedded')),
                                array(
                                    'id' => self::PAYMENT_CHECKOUT_REDIRECT_METHOD,
                                    'name' => $this->l('Redirect to Payment Page')),
                            ),
                            'id'    => 'id',
                            'name'  => 'name',
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * @return array
     */
    protected function getAdminGeneralSettingsForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('General Settings'),
                    'icon'  => 'icon-cogs',
                ),
                'input'  => array(
                    array(
                        'type'    => 'switch',
                        'label'   => $this->l('Live Mode'),
                        'name'    => 'mpgs_mode',
                        'is_bool' => true,
                        'desc'    => '',
                        'values'  => array(
                            array(
                                'id'    => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ),
                            array(
                                'id'    => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ),
                        ),
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->l('Gateway URL'),
                        'name'     => 'mpgs_api_url_custom',
                        'required' => true,
                    ),
                    array(
                        'type'    => 'switch',
                        'label'   => $this->l('Send Line Items'),
                        'desc'    => $this->l('Include line item details on gateway order'),
                        'name'    => 'mpgs_lineitems_enabled',
                        'is_bool' => true,
                        'values'  => array(
                            array(
                                'id'    => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ),
                            array(
                                'id'    => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ),
                        ),
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->l('Merchant ID'),
                        'name'     => 'mpgs_merchant_id',
                        'required' => true,
                    ),
                    array(
                        'type'     => 'password',
                        'label'    => $this->l('API Password'),
                        'name'     => 'mpgs_api_password',
                        'required' => true,
                    ),
                    array(
                        'type'     => 'password',
                        'label'    => $this->l('Webhook Secret'),
                        'name'     => 'mpgs_webhook_secret',
                        'required' => false,
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->l('Test Merchant ID'),
                        'name'     => 'test_mpgs_merchant_id',
                        'required' => true,
                    ),
                    array(
                        'type'     => 'password',
                        'label'    => $this->l('Test API Password'),
                        'name'     => 'test_mpgs_api_password',
                        'required' => true,
                    ),
                    array(
                        'type'     => 'password',
                        'label'    => $this->l('Test Webhook Secret'),
                        'name'     => 'test_mpgs_webhook_secret',
                        'required' => false,
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * @return array
     */
    protected function getAdminAdvancedSettingForm()
    {
        return array(
            'form' => array(
                'legend' => array(
                    'title' => $this->l('Advanced Parameters'),
                    'icon'  => 'icon-cogs',
                ),
                'input'  => array(
                    array(
                        'type'    => 'select',
                        'label'   => $this->l('Logging Verbosity'),
                        'desc'    => $this->l('Allows to set the verbosity level of var/logs/mastercard.log'),
                        'name'    => 'mpgs_logging_level',
                        'options' => array(
                            'query' => array(
                                array('id' => \Monolog\Logger::DEBUG, 'name' => $this->l('Enabled')),
                                array('id' => \Monolog\Logger::EMERGENCY, 'name' => $this->l('Disabled')),
                            ),
                            'id'    => 'id',
                            'name'  => 'name',
                        ),
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->l('Gateway Order ID Prefix'),
                        'desc'     => $this->l('Should be specified in case multiple integrations use the same Merchant ID'),
                        'name'     => 'mpgs_order_prefix',
                        'required' => false,
                    ),
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * @return array
     */
    protected function getMerchantInfoForm()
    {
        return array(
            'form' => array(
                'form_class' => 'merchantinfo',
                'legend'     => array(
                    'title'  => $this->l('Merchant Information'),
                    'icon'   => 'icon-cogs',
                    'class'  => 'merchantinfo',
                ),
                'input'  => array(
                    array(
                        'type'    => 'switch',
                        'label'   => $this->l('Enable/Disable'),
                        'name'    => 'mpgs_mi_active',
                        'is_bool' => true,
                        'desc'    => '',
                        'values'  => array(
                            array(
                                'id'    => 'active_on',
                                'value' => true,
                                'label' => $this->l('Enabled'),
                            ),
                            array(
                                'id'    => 'active_off',
                                'value' => false,
                                'label' => $this->l('Disabled'),
                            ),
                        ),
                    ),
                    array(
                            'type'     => 'text',
                            'label'    => $this->l('Merchant Name'),
                            'desc'     => $this->l('Name of your business (up to 40 characters) to be shown to the payer during the payment interaction.'),
                            'name'     => 'mpgs_mi_merchant_name',
                            'required' => false,
                            'maxlength' => 40,
                        ),
                        array(
                            'type'     => 'text',
                            'label'    => $this->l('Address line 1'),
                            'desc'     => $this->l('The first line of your business address (up to 100 characters) to be shown to the payer during the payment interaction.'),
                            'name'     => 'mpgs_mi_address_line1',
                            'required' => false,
                            'maxlength' => 100,
                        ),
                        array(
                            'type'     => 'text',
                            'label'    => $this->l('Address line 2'),
                            'desc'     => $this->l('The second line of your business address (up to 100 characters) to be shown to the payer during the payment interaction.'),
                            'name'     => 'mpgs_mi_address_line2',
                            'required' => false,
                            'maxlength' => 100,
                        ),
                        array(
                            'type'     => 'text',
                            'label'    => $this->l('Postcode / ZIP'),
                            'desc'     => $this->l('The postal or ZIP code of your business address (up to 100 characters) to be shown to the payer during the payment interaction.'),
                            'name'     => 'mpgs_mi_postalcode',
                            'required' => false,
                            'maxlength' => 100,
                        ),
                        array(
                            'type'     => 'text',
                            'label'    => $this->l('Country / State'),
                            'desc'     => $this->l('The country or state of your business address (up to 100 characters) to be shown to the payer during the payment interaction.'),
                            'name'     => 'mpgs_mi_country',
                            'required' => false,
                            'maxlength' => 100,
                        ),
                        array(
                            'type'     => 'text',
                            'label'    => $this->l('Email'),
                            'desc'     => $this->l('The email address of your business to be shown to the payer during the payment interaction. (e.g. an email address for customer service).'),
                            'name'     => 'mpgs_mi_email',
                            'required' => false,
                        ),
                        array(
                            'type'     => 'text',
                            'label'    => $this->l('Phone'),
                            'desc'     => $this->l('The phone number of your business (up to 20 characters) to be shown to the payer during the payment interaction.'),
                            'name'     => 'mpgs_mi_phone',
                            'required' => false,
                            'maxlength' => 20,
                        ),
                        array(
                            'type'     => 'file',
                            'label'    => $this->l('Logo'),
                            'desc'     => $this->l('The URL of your business logo (JPEG, PNG, or SVG) to be shown to the payer during the payment interaction. The logo should be 140x140 pixels, and the URL must be secure (e.g., https://). Size exceeding 140 pixels will be auto resized.'),
                            'name'     => 'mpgs_mi_logo',
                            'required' => false,
                            'display'  => 'file',
                            'image' => Configuration::get('mpgs_mi_logo')
                                ? '<div id="mpgs-logo-preview" class="mpgs-logo-container">
                                        <img src="' . Configuration::get('mpgs_mi_logo') . '" class="mpgs-logo-img" />
                                        <a href="#" class="mpgs-logo-remove" onclick="return mpgsDeleteLogo();">&times;</a>
                                        <input type="hidden" id="mpgs_delete_logo" name="mpgs_delete_logo" value="0" />
                                   </div>'
                                : '<span>No image available</span>',
                        )
                ),
                'submit' => array(
                    'title' => $this->l('Save'),
                ),
            ),
        );
    }

    /**
     * Save form data.
     */
    protected function postProcess()
    {
        $formValues = $this->getAdminFormValues();

        $this->updateConfigurationValues($formValues);
        $this->handleLogoDeletion();
        $this->handleCustomApiUrl();
        $this->updateTranslatedFields();

        $this->htmlContent .= $this->displayConfirmation($this->l('Settings updated'));
        $this->enterprisedatasend();

        $this->validateGatewayConnection();
    }

    /**
     * Update configuration values except hidden sensitive ones.
     */
    private function updateConfigurationValues(array $formValues): void
    {
        foreach ($formValues as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $hiddenKeys = [
                'mpgs_api_password',
                'test_mpgs_api_password',
                'mpgs_webhook_secret',
                'test_mpgs_webhook_secret',
            ];

            if (in_array($key, $hiddenKeys) && !$value) {
                continue;
            }

            Configuration::updateValue($key, $value);
        }
    }

    /**
     * Handle logo deletion and file cleanup.
     */
    private function handleLogoDeletion(): void
    {
        if (Tools::getValue('mpgs_delete_logo') != '1') {
            return;
        }

        $existingLogoUrl = Configuration::get('mpgs_mi_logo');

        if ($existingLogoUrl) {
            $parsedPath   = parse_url($existingLogoUrl, PHP_URL_PATH);
            $relativePath = preg_replace('#^.*(/modules/.*)$#', '$1', $parsedPath);
            $localPath    = _PS_ROOT_DIR_ . $relativePath;

            if (file_exists($localPath)) {
                unlink($localPath);
            }
        }

        Configuration::updateValue('mpgs_mi_logo', '');
    }

    /**
     * Handle custom API URL formatting and saving.
     */
    private function handleCustomApiUrl(): void
    {
        if (Tools::getValue('mpgs_api_url_custom') === null) {
            return;
        }

        $apiurl = trim(Tools::getValue('mpgs_api_url_custom'));

        if ($apiurl !== '') {
            if (!preg_match('#^' . self::HTTPS_PREFIX .'#', $apiurl)) {
                $apiurl = self::HTTPS_PREFIX . $apiurl;
            }

            if (substr($apiurl, -1) !== '/') {
                $apiurl .= '/';
            }

            Configuration::updateValue('mpgs_api_url_custom', $apiurl);
        } else {
            Configuration::updateValue('mpgs_api_url_custom', '');
        }
    }

    /**
     * Handle multilingual configuration fields.
     */
    private function updateTranslatedFields(): void
    {
        $translatedFields = ['mpgs_hc_title'];
        $languages = Language::getLanguages(false);

        foreach ($translatedFields as $field) {
            $translatedValues = [];
            foreach ($languages as $lang) {
                if (Tools::getIsset($field . '_' . $lang['id_lang'])) {
                    $translatedValues[$lang['id_lang']] = Tools::getValue($field . '_' . $lang['id_lang']);
                }
            }
            Configuration::updateValue($field, $translatedValues);
        }
    }

    /**
     * Validate API connection to the gateway.
     */
    private function validateGatewayConnection(): void
    {
        try {
            $client = new GatewayService(
                $this->getApiEndpoint(),
                $this->getApiVersion(),
                $this->getConfigValue('mpgs_merchant_id'),
                $this->getConfigValue('mpgs_api_password'),
                $this->getWebhookUrl()
            );
            $client->paymentOptionsInquiry();
            Configuration::updateValue('mpgs_gateway_validated', 1);
        } catch (Exception $e) {
            Configuration::updateValue('mpgs_gateway_validated', 0);
        }
    }


    /**
     * @param $params
     *
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function hookDisplayAdminOrderLeft($params)
    {
        return $this->renderActionsSections($params, 'views/templates/hook/order_actions.tpl');
    }

    /**
     * @param $params
     *
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function hookDisplayAdminOrderSideBottom($params)
    {
        return $this->renderActionsSections($params, 'views/templates/hook/order_actions_v1770.tpl');
    }

    /**
     * @param array $params
     */
    public function hookActionObjectOrderSlipAddAfter($params)
    {
        /** @var OrderSlip $res */
        $orderSlip = $params['object'];

        $order = new Order($orderSlip->id_order);

        if ($order->payment !== self::PAYMENT_CODE && $order->payment !== 'MPGS') {
            return;
        }

        $refundService = new MpgsRefundService($this);
        $amount = (string)($orderSlip->total_shipping_tax_incl + $orderSlip->total_products_tax_incl);

        if (!Tools::getValue('withdrawToCustomer')) {
            return;
        }

        try {
            $response = $refundService->execute(
                $order,
                array(
                    new TransactionResponseHandler(),
                    new TransactionStatusResponseHandler(),
                ),
                $amount
            );

            $refund                 = new MpgsRefund();
            $refund->order_id       = $order->id;
            $refund->total          = $amount;
            $refund->transaction_id = $response['transaction']['id'];
            $refund->order_slip_id  = $orderSlip->id;
            $refund->add();

        } catch (Exception $e) {
            $orderSlip->delete();
            Tools::redirectAdmin((new Link())->getAdminLink('AdminOrders', true, array(), array(
                'vieworder' => '',
                'id_order'  => $order->id,
            )));
        }
    }

    /**
     * @param $params
     * @param $view
     *
     * @return string
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function renderActionsSections($params, $view)
    {
        if (!$this->active) {
            return '';
        }

        $order = new Order($params['id_order']);
        if ($order->payment != self::PAYMENT_CODE && $order->payment !== 'MPGS') {
            return '';
        }

        $isAuthorized = $order->current_state == Configuration::get('MPGS_OS_AUTHORIZED');
        $canVoid      = $isAuthorized;
        $canCapture   = $isAuthorized;
        $canRefund    = $order->current_state == Configuration::get('PS_OS_PAYMENT');
        $canReview    = $order->current_state == Configuration::get('MPGS_OS_REVIEW_REQUIRED');
        $canAction    = $isAuthorized || $canVoid || $canCapture || $canRefund;

        $hidePartialRefundButton = true;

        // Assuming you have constants or configuration keys for "Refunded" and "Void" statuses
        $refundedStateId = $order->current_state == Configuration::get('PS_OS_REFUND');
        $voidStateId     = $order->current_state == Configuration::get('PS_OS_CANCELED');
        $paymentStateId  = $order->current_state == Configuration::get('PS_OS_PAYMENT');
        $partialrefund   = $order->current_state == Configuration::get('MPGS_OS_PARTIALLY_REFUNDED');
        if($paymentStateId || $partialrefund){
            $hidePartialRefundButton = false;
        }
        // Check if the order status is either "Refunded" or "Void"
        if ($refundedStateId || $voidStateId) {
            $hidePartialRefundButton = true;
        }

        $this->smarty->assign(array(
            'module_dir'         => $this->_path,
            'order'              => $order,
            'mpgs_order_ref'     => $this->getOrderRef($order),
            'can_void'           => $canVoid,
            'can_capture'        => $canCapture,
            'can_refund'         => $canRefund && !MpgsRefund::hasExistingRefunds($order->id),
            'can_partial_refund' => !MpgsRefund::hasExistingFullRefund($order->id),
            'is_authorized'      => $isAuthorized,
            'can_review'         => $canReview,
            'can_action'         => $canAction,
            'has_refunds'        => MpgsRefund::hasExistingRefunds($order->id),
            'refunds'            => MpgsRefund::getAllRefundsByOrderId($order->id),
            'has_voids'          => MpgsVoid::hasExistingVoids($order->id),
            'voids'              => MpgsVoid::getAllVoidsByOrderId($order->id),
            'hidePartialRefundButton'=> $hidePartialRefundButton,
        ));

        return $this->display(__FILE__, $view);
    }

    /**
     * @return array
     * @throws SmartyException
     * @throws Exception
     */
    public function hookPaymentOptions()
    {
        if (!$this->active) {
            return array();
        }

        $this->context->smarty->assign(array(
            'mpgs_config' => array(
                'merchant_id' => $this->getConfigValue('mpgs_merchant_id'),
                'amount'      => $this->context->cart->getOrderTotal(),
                'currency'    => $this->context->currency->iso_code,
                'order_id'    => $this->getNewOrderRef(),
                'method'      => Configuration::get('mpgs_hc_payment_method'),
            ),
        ));

        $methods = array();

        if (Configuration::get('mpgs_hc_active') && Configuration::get('mpgs_gateway_validated')) {
            $methods[] = $this->getHostedCheckoutPaymentOption();
        }

        return $methods;
    }

    /**
     * @param $field
     *
     * @return string|false
     */
    public function getConfigValue($field)
    {
        $testPrefix = '';
        if (!Configuration::get('mpgs_mode')) {
            $testPrefix = 'test_';
        }

        return Configuration::get($testPrefix.$field);
    }

    /**
     * @return PaymentOption
     * @throws SmartyException
     */
    protected function getHostedCheckoutPaymentOption()
    {
        $form   = $this->generateHostedCheckoutForm();

        $option = new PaymentOption();
        $option
            ->setModuleName($this->name.'_hc')
            ->setCallToActionText(Configuration::get('mpgs_hc_title', $this->context->language->id))
            ->setForm($form);

        return $option;
    }

    /**
     * @return string
     * @throws SmartyException
     * @throws Exception
     */
    protected function generateHostedCheckoutForm()
    {
        $this->context->smarty->assign(array(
            'hostedcheckout_action_url'    => $this->context->link->getModuleLink($this->name, 'hostedcheckout',
                array(), true),
            'hostedcheckout_cancel_url'    => $this->context->link->getModuleLink($this->name, 'hostedcheckout',
                array('cancel' => 1), true),
            'hostedcheckout_component_url' => $this->getHostedCheckoutJsComponent(),
        ));

        return $this->context->smarty->fetch('module:mastercard/views/templates/front/methods/hostedcheckout/form.tpl');
    }

    /**
    * @return string
    * @throws Exception
    */
    private function checkApiEndpoint($url){

         if (preg_match('#^' . self::HTTPS_PREFIX . '.+/$#', $url)) {
        
            $url = preg_replace('#^' . self::HTTPS_PREFIX . '#', '', $url);
            $url = rtrim($url, '/');
            return $url;

        } else {

            return $url;
        }
    }

    /**
     * @return string
     * @throws Exception
     */
    public function getApiEndpoint()
    {
        if(Configuration::get('mpgs_api_url_custom')){
            $apiUrl    = Configuration::get('mpgs_api_url_custom');
            $endpoint   = $this->checkApiEndpoint($apiUrl);
        } else {
            $endpoint   = Configuration::get('mpgs_api_url');
        }

        if (!$endpoint) {
            throw new MasterCardPaymentException("API endpoint not specified.");
        }

        return $endpoint;
    }

    /**
     * @return string
     * @throws Exception
     * https://mtf.gateway.mastercard.com/checkout/version/50/checkout.js
     */
    public function getHostedCheckoutJsComponent()
    {
        $cacheBust = (int)round(microtime(true));

        return self::HTTPS_PREFIX .$this->getApiEndpoint().'/static/checkout/checkout.min.js?_='.$cacheBust;
    }

    /**
     * @return string
     * @throws Exception
     * https://mtf.gateway.mastercard.com/form/version/50/merchant/<MERCHANTID>/session.js
     */
    public function getHostedSession3DSUrl()
    {
        $cacheBust = (int)round(microtime(true));

        return self::HTTPS_PREFIX .$this->getApiEndpoint().'/static/threeDS/'.$this->get3DSLibVersion().'/three-ds.min.js?_='.$cacheBust;
    }

    /**
     * @return string
     */
    public function getWebhookUrl()
    {
        return Configuration::get('mpgs_webhook_url') ?: $this->context->link->getModuleLink($this->name, 'webhook',
            array(), true);
    }

    /**
     * @param Order $order
     *
     * @return string
     */
    public function getOrderRef($order)
    {
        $cartId = (string)$order->id_cart;
        $prefix = Configuration::get('mpgs_order_prefix') ?: '';

        return $prefix.$cartId;
    }

    /**
     * @return string
     */
    public function getNewOrderRef()
    {
        $cartId = (string)Context::getContext()->cart->id;
        $prefix = Configuration::get('mpgs_order_prefix') ?: '';

        return $prefix.$cartId;
    }

    /**
     * @param Order $order
     * @param string $txnId
     *
     * @return OrderPayment|null
     */
    public function getTransactionById($order, $txnId)
    {
        foreach ($order->getOrderPayments() as $payment) {
            if ($payment->transaction_id == $txnId) {
                return $payment;
            }
        }

        return null;
    }


    /**
     * @param float $deltaAmount
     *
     * @return array|null
     */
    public function getOrderItems($deltaAmount = 0.00)
    {
        if (!Configuration::get('mpgs_lineitems_enabled')) {
            return null;
        }

        $items     = $this->context->cart->getProducts(false, false, $this->context->country->id, true);
        $cartItems = array();

        $hasDelta  = $deltaAmount > 0;

        /** @var Product $item */
        foreach ($items as $item) {
            $catyItem = array(
                'name'      => GatewayService::safe($item['name'], 127),
                'quantity'  => GatewayService::numeric($item['cart_quantity']),
                'sku'       => GatewayService::safe($item['reference'], 127),
                'unitPrice' => GatewayService::numeric($item['price_wt']),
            );

            if ($hasDelta && $item['cart_quantity']) {
                $hasDelta     = false;
                $deltaPerItem = (ceil($deltaAmount / $item['cart_quantity']));
                $catyItem['unitPrice'] = GatewayService::numeric($item['price_wt'] - $deltaPerItem);
            }

            $cartItems[] = $catyItem;
        }

        return empty($cartItems) ? null : $cartItems;
    }

    /**
     * @param float $deltaAmount
     *
     * @return string|null
     * @throws Exception
     */
    public function getShippingHandlingAmount($deltaAmount = 0)
    {
        if (!Configuration::get('mpgs_lineitems_enabled')) {
            return null;
        }

        $total = Context::getContext()->cart->getOrderTotal();

        return GatewayService::numeric(
            $total - (float)$this->getItemAmount($deltaAmount)
        );
    }

    /**
     * @param float $deltaAmount
     *
     * @return string|null
     */
    public function getItemAmount($deltaAmount = 0.00)
    {
        $items = $this->getOrderItems($deltaAmount);

        if (!$items) {
            return null;
        }

        $amount = 0.0;
        foreach ($items as $item) {
            $amount += (float)$item['unitPrice'] * (float)$item['quantity'];
        }

        return GatewayService::numeric($amount);
    }

    /**
     * @return bool
     */
    public function isUpgradeModuleRefund()
    {
        $dbPrefix    = _DB_PREFIX_;
        $mysqlEngine = _MYSQL_ENGINE_;
        $query       = <<<EOT
        CREATE TABLE IF NOT EXISTS `{$dbPrefix}mpgs_payment_refunds` (
            `refund_id` int(10) unsigned NOT NULL auto_increment,
            `order_id` int(10) unsigned NOT NULL,
            `order_slip_id` int(10) unsigned,
            `total` decimal(20, 6) default 0.000000 NOT NULL,
            `transaction_id` varchar(255) NOT NULL,
             PRIMARY KEY  (`refund_id`)
        ) ENGINE={$mysqlEngine} DEFAULT CHARSET=utf8;
        EOT;

        return DB::getInstance()->execute($query);
    }

    /**
     * @return bool
     */
    public function isUpgradeModuleDrop()
    {
        $dbPrefix = _DB_PREFIX_;
        $query    = <<<EOT
        DROP TABLE IF EXISTS `{$dbPrefix}mpgs_payment_order_suffix`;
        EOT;

        return DB::getInstance()->execute($query);
    }

    /**
     * @return bool
     */
    public function isUpgradeModuleVoid()
    {
        $dbPrefix    = _DB_PREFIX_;
        $mysqlEngine = _MYSQL_ENGINE_;
        $query       = <<<EOT
        CREATE TABLE IF NOT EXISTS `{$dbPrefix}mpgs_payment_voids` (
            `void_id` int(10) unsigned NOT NULL auto_increment,
            `order_id` int(10) unsigned NOT NULL,
            `total` decimal(20, 6) default 0.000000 NOT NULL,
            `transaction_id` varchar(255) NOT NULL,
             PRIMARY KEY  (`void_id`)
        ) ENGINE={$mysqlEngine} DEFAULT CHARSET=utf8;
        EOT;

        return DB::getInstance()->execute($query);
    }

    /**
     * @return string|null
     */
    public function enterprisedatasend()
    {
        $countryId      = Configuration::get('PS_COUNTRY_DEFAULT');
        $country        = new Country($countryId);
        $countryName    = $country->name[$this->context->language->id];
        $countryCode    = $country->iso_code;
        $flag           = Configuration::get('ENTERPRISE_SET_FLAG');
        $version        = Configuration::get('ENTERPRISE_VERSION');
        $storeName      = Configuration::get('PS_SHOP_NAME');
        $storeUrl       = Configuration::get('PS_SHOP_DOMAIN');
        $publicKey      = Configuration::get('mpgs_merchant_id');
        $privateKey     = Configuration::get('mpgs_api_password');
        $data[]         = null;
        if (!empty($publicKey && $privateKey)) {
            if (($version != $this->version) && $flag || empty($flag)) {
                $data = [
                    'repo_name'      => 'gateway-prestashop-mastercard-module',
                    'plugin_type'    => 'enterprise',
                    'tag_name'       => $this->version,
                    'latest_release' => '1',
                    'country_code'   => $countryCode,
                    'country'        => $countryName,
                    'shop_name'      => $storeName,
                    'shop_url'       => $storeUrl,
                ];
                Configuration::updateValue('ENTERPRISE_SET_FLAG', 1);
                Configuration::updateValue('ENTERPRISE_VERSION', $this->version);
            } else {
                return null;
            }
        }
        
        // Define the URL for the WordPress REST API endpoint
        $url         = self::MPGS_API_URL;
        // Set your Bearer token here
        $bearerToken = self::ENTERPRISE_MODULE_KEY;
        // Set up headers
        $headers     = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $bearerToken,
        ];
        // Initialize cURL
        $ch          = curl_init($url);

        // Set cURL options
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));

        // Execute the request
        $response = curl_exec($ch);
        // Check for errors
        if (curl_errno($ch)) {
            $errorMsg = curl_error($ch);
            curl_close($ch);
            return 'Error: ' . $errorMsg;
        }

        // Close cURL
        curl_close($ch);
        return $response;
    }
}
