<?php
/**
 * Copyright (c) 2019-2024 Mastercard
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 *
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

define('MPGS_ISO3_COUNTRIES', include dirname(__FILE__).'/iso3.php');

require_once(dirname(__FILE__).'/vendor/autoload.php');
require_once(dirname(__FILE__).'/gateway.php');
require_once(dirname(__FILE__).'/handlers.php');
require_once(dirname(__FILE__).'/service/MpgsRefundService.php');
require_once(dirname(__FILE__).'/model/MpgsRefund.php');
require_once(dirname(__FILE__).'/model/MpgsVoid.php');

/**
 * @property bool bootstrap
 */
class Mastercard extends PaymentModule
{
    const PAYMENT_CODE                       = 'MPGS';
    const MPGS_API_VERSION                   = '100';
    const MPGS_3DS_LIB_VERSION               = '1.3.0';
    const PAYMENT_CHECKOUT_SESSION_PURCHASE  = 'PURCHASE';
    const PAYMENT_CHECKOUT_SESSION_AUTHORIZE = 'AUTHORIZE';
    const PAYMENT_CHECKOUT_EMBEDDED_METHOD   = 'EMBEDDED';
    const PAYMENT_CHECKOUT_REDIRECT_METHOD   = 'REDIRECT';
    const ENTERPRISE_MODULE_KEY              = '3cfa292619f39b06479454445cd1c7668bd6ad752b74e78af280f428eeff5226';
    const MPGS_API_URL                       = 'https://mpgs.fingent.wiki/wp-json/mpgs/v2/update-repo-status';

    /**
     * @var string
     */
    protected $_html = '';

    /**
     * @var string
     */
    protected $controllerAdmin;

    /**
     * @var array
     */
    protected $_postErrors = array();

    /**
     * Mastercard constructor.
     */
    public function __construct()
    {
        $this->module_key = '5e026a47ceedc301311e969c872f8d41';
        $this->name       = 'mastercard';
        $this->tab        = 'payments_gateways';
        $this->version    = '1.4.4';
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
        $this->displayName      = $this->l('Mastercard Payment Gateway Services');
        $this->description      = $this->l('Mastercard Payment Gateway Services module for Prestashop');
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
        if (extension_loaded('curl') == false) {
            $this->_errors[] = $this->l('You have to enable the cURL extension on your server to install this module');

            return false;
        }

        if (!$this->installOrderState()) {
            return false;
        }

        // Install admin tab
        if (!$this->installTab()) {
            return false;
        }

        return parent::install() &&
               $this->registerHook('paymentOptions') &&
               $this->registerHook('displayAdminOrderLeft') &&
               $this->registerHook('displayAdminOrderSideBottom') &&
               $this->registerHook('displayBackOfficeOrderActions') &&
               $this->registerHook('actionObjectOrderSlipAddAfter') &&
               $this->registerHook('displayBackOfficeHeader') &&
               $this->upgrade_module_1_3_3() &&
               $this->upgrade_module_1_3_6() &&
               $this->upgrade_module_1_3_7();
    }

    /**
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    public function uninstall()
    {
        Configuration::deleteByName('mpgs_hc_title');
        $this->unregisterHook('paymentOptions');
        $this->unregisterHook('displayBackOfficeOrderActions');
        $this->unregisterHook('displayAdminOrderLeft');
        $this->unregisterHook('displayAdminOrderSideBottom');
        $this->unregisterHook('actionObjectOrderSlipAddAfter');
        $this->unregisterHook('displayBackOfficeHeader');
        $this->uninstallTab();
        return parent::uninstall();
    }

    /**
     * @param $params
     */
    public function hookDisplayBackOfficeOrderActions($params)
    {
        // noop
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
        $tab = new Tab();
        $tab->class_name = $this->controllerAdmin;
        $tab->active = 1;
        $tab->name = array();
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = $this->name;
        }
        $tab->id_parent = -1;
        $tab->module = $this->name;

        return $tab->add();
    }

    /**
     * @return bool
     * @throws PrestaShopDatabaseException
     * @throws PrestaShopException
     */
    private function uninstallTab()
    {
        $id_tab = (int)Tab::getIdFromClassName($this->controllerAdmin);
        $tab = new Tab($id_tab);
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
    public function installOrderState()
    {
        if (!Configuration::get('MPGS_OS_PAYMENT_WAITING')
            || !Validate::isLoadedObject(new OrderState(Configuration::get('MPGS_OS_PAYMENT_WAITING')))) {
            $order_state = new OrderState();
            foreach (Language::getLanguages() as $language) {
                $order_state->name[$language['id_lang']] = 'Awaiting Payment';
            }
            $order_state->send_email = false;
            $order_state->color = '#4169E1';
            $order_state->hidden = false;
            $order_state->delivery = false;
            $order_state->logable = false;
            $order_state->invoice = false;
            $order_state->paid = false;
            if ($order_state->add()) {
                $source = _PS_ROOT_DIR_.'/img/os/10.gif';
                $destination = _PS_ROOT_DIR_.'/img/os/'.(int)$order_state->id.'.gif';
                copy($source, $destination);
            }

            Configuration::updateValue('MPGS_OS_PAYMENT_WAITING', (int)$order_state->id);
        }
        if (!Configuration::get('MPGS_OS_AUTHORIZED')
            || !Validate::isLoadedObject(new OrderState(Configuration::get('MPGS_OS_AUTHORIZED')))) {
            $order_state = new OrderState();
            foreach (Language::getLanguages() as $language) {
                $order_state->name[$language['id_lang']] = 'Payment Authorized';
                $order_state->template[$language['id_lang']] = 'payment';
            }
            $order_state->send_email = true;
            $order_state->color = '#4169E1';
            $order_state->hidden = false;
            $order_state->delivery = false;
            $order_state->logable = true;
            $order_state->paid = true;
            $order_state->invoice = false;
            if ($order_state->add()) {
                $source = _PS_ROOT_DIR_.'/img/os/10.gif';
                $destination = _PS_ROOT_DIR_.'/img/os/'.(int)$order_state->id.'.gif';
                copy($source, $destination);
            }

            Configuration::updateValue('MPGS_OS_AUTHORIZED', (int)$order_state->id);
        }
        if (!Configuration::get('MPGS_OS_REVIEW_REQUIRED')
            || !Validate::isLoadedObject(new OrderState(Configuration::get('MPGS_OS_REVIEW_REQUIRED')))) {
            $order_state = new OrderState();
            foreach (Language::getLanguages() as $language) {
                $order_state->name[$language['id_lang']] = 'Payment Review Required';
            }
            $order_state->send_email = false;
            $order_state->color = '#4169E1';
            $order_state->hidden = false;
            $order_state->delivery = false;
            $order_state->logable = false;
            $order_state->invoice = false;
            $order_state->paid = false;
            if ($order_state->add()) {
                $source = _PS_ROOT_DIR_.'/img/os/10.gif';
                $destination = _PS_ROOT_DIR_.'/img/os/'.(int)$order_state->id.'.gif';
                copy($source, $destination);
            }

            Configuration::updateValue('MPGS_OS_REVIEW_REQUIRED', (int)$order_state->id);
        }
        if (!Configuration::get('MPGS_OS_FRAUD')
            || !Validate::isLoadedObject(new OrderState(Configuration::get('MPGS_OS_FRAUD')))) {
            $order_state = new OrderState();
            foreach (Language::getLanguages() as $language) {
                $order_state->name[$language['id_lang']] = 'Suspected Fraud';
            }
            $order_state->send_email = false;
            $order_state->color = '#DC143C';
            $order_state->hidden = false;
            $order_state->delivery = false;
            $order_state->logable = false;
            $order_state->invoice = false;
            $order_state->paid = false;
            if ($order_state->add()) {
                $source = _PS_ROOT_DIR_.'/img/os/6.gif';
                $destination = _PS_ROOT_DIR_.'/img/os/'.(int)$order_state->id.'.gif';
                copy($source, $destination);
            }

            Configuration::updateValue('MPGS_OS_FRAUD', (int)$order_state->id);
        }
        if (!Configuration::get('MPGS_OS_PARTIALLY_REFUNDED')
            || !Validate::isLoadedObject(new OrderState(Configuration::get('MPGS_OS_PARTIALLY_REFUNDED')))) {
            $order_state = new OrderState();
            foreach (Language::getLanguages() as $language) {
                $order_state->name[$language['id_lang']] = 'Partially Refunded';
                $order_state->template[$language['id_lang']] = 'refund';
            }
            $order_state->send_email = true;
            $order_state->color = '#01B887';
            $order_state->hidden = false;
            $order_state->delivery = false;
            $order_state->logable = true;
            $order_state->paid = true;
            $order_state->invoice = false;
            if ($order_state->add()) {
                $source = _PS_ROOT_DIR_.'/img/os/7.gif';
                $destination = _PS_ROOT_DIR_.'/img/os/'.(int)$order_state->id.'.gif';
                copy($source, $destination);
            }

            Configuration::updateValue('MPGS_OS_PARTIALLY_REFUNDED', (int)$order_state->id);
        }

        return true;
    }

    public function checkForUpdates()
    {
        // Get the latest release information from GitHub
        $latestRelease = $this->getLatestGitHubVersion();

        // Compare the latest release version with the current module version
        if ($latestRelease !== null && version_compare($latestRelease['version'], $this->version, '>')) {
            // Newer version available
            return [
                'available' => true,
                'version' => $latestRelease['version'],
                'download_url' => $latestRelease['download_url']
            ];
        } else {
            // Module is up to date
            return [
                'available' => false,
                'version' => $this->version
            ];
        }
    }

    private function getLatestGitHubVersion() {
        $owner = 'fingent-corp';
        $repo = 'gateway-prestashop-mastercard-module';
        $url = "https://api.github.com/repos/{$owner}/{$repo}/releases/latest";
        $ch = curl_init($url);
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
                'version' => $data['tag_name'],
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
        // Initialize _html variable
        $this->_html = '';

        // Process form submission
        if (Tools::isSubmit('submitMastercardModule')) {
            $this->_postValidation();
            if (!count($this->_postErrors)) {
                $this->postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        }

        // Add JavaScript file
        $this->context->controller->addJS($this->_path.'/views/js/back.js');

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
            'module_version' => $moduleVersion, // Pass the module version to the template
        ]);

        // Render notification template
        $notificationContent = $this->display($this->local_path, 'views/templates/admin/update.tpl');

        // Append notification content to _html
        $this->_html .= $notificationContent;

        // Render form success or error messages
        if (isset($this->_htmlSuccess)) {
            $this->_html .= $this->displayConfirmation($this->_htmlSuccess);
        }
        if (isset($this->_htmlWarning)) {
            $this->_html .= $this->displayWarning($this->_htmlWarning);
        }
        if (isset($this->_htmlError)) {
            $this->_html .= $this->displayError($this->_htmlError);
        }

        // Render main configuration template
        $mainContent = $this->display($this->local_path, 'views/templates/admin/configure.tpl');

        // Append main content to _html
        $this->_html .= $mainContent;

        // Render form
        $this->_html .= $this->renderForm();

        // Return the final content
        return $this->_html;
    }

    /**
     * @return void
     */
    protected function _postValidation()
    {
        if (!Tools::getValue('mpgs_api_url')) {
            if (!Tools::getValue('mpgs_api_url_custom')) {
                $this->_postErrors[] = $this->l('Custom API Endpoint is required.');
            }
        }
        if (Tools::getValue('mpgs_mode') === "1") {
            if (!Tools::getValue('mpgs_merchant_id')) {
                $this->_postErrors[] = $this->l('Merchant ID is required.');
            }
        } else {
            if (!Tools::getValue('test_mpgs_merchant_id')) {
                $this->_postErrors[] = $this->l('Test Merchant ID is required.');
            }
        }
    }

    /**
     * Create the form that will be displayed in the configuration of your module.
     * @throws PrestaShopException
     */
    protected function renderForm()
    {
        $helper = new HelperForm();

        $helper->show_toolbar = false;
        $helper->table = $this->table;
        $helper->module = $this;
        $helper->default_form_language = $this->context->language->id;
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG', 0);
        $helper->identifier = $this->identifier;
        $helper->submit_action = 'submitMastercardModule';
        $helper->currentIndex = $this->context->link->getAdminLink('AdminModules', false)
                                .'&configure='.$this->name.'&tab_module='.$this->tab.'&module_name='.$this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars = array(
            'fields_value' => $this->getAdminFormValues(), /* Add values for your inputs */
            'languages'    => $this->context->controller->getLanguages(),
            'id_language'  => $this->context->language->id,
        );

        return $helper->generateForm(array(
            $this->getAdminGeneralSettingsForm(),
            $this->getAdminHostedCheckoutForm(),
            $this->getAdminAdvancedSettingForm(),
        ));
    }

    /**
     * @return array
     */
    protected function getApiUrls()
    {
        return array(
            'eu-gateway.mastercard.com'  => $this->l('eu-gateway.mastercard.com'),
            'ap-gateway.mastercard.com'  => $this->l('ap-gateway.mastercard.com'),
            'na-gateway.mastercard.com'  => $this->l('na-gateway.mastercard.com'),
            'mtf.gateway.mastercard.com' => $this->l('mtf.gateway.mastercard.com'),
            ''                           => $this->l('Other'),
        );
    }

    /**
     * @return array
     */
    protected function getAdminFormValues()
    {
        $hcTitle = array();
        $hsTitle = array();
        $languages = Language::getLanguages(false);
        foreach ($languages as $lang) {
            $value = Tools::getValue(
                'mpgs_hc_title_'.$lang['id_lang'],
                Configuration::get('mpgs_hc_title', $lang['id_lang'])
            );
            $hcTitle[$lang['id_lang']] = $value ? $value : $this->l('MasterCard Hosted Checkout');

            $value = Tools::getValue(
                'mpgs_hs_title_'.$lang['id_lang'],
                Configuration::get('mpgs_hs_title', $lang['id_lang'])
            );
            $hsTitle[$lang['id_lang']] = $value ? $value : $this->l('MasterCard Hosted Session');
        }

        return array(
            'mpgs_hc_active'         => Tools::getValue('mpgs_hc_active', Configuration::get('mpgs_hc_active')),
            'mpgs_hc_title'          => $hcTitle,
            'mpgs_hc_payment_action' => Tools::getValue('mpgs_hc_payment_action',
                Configuration::get('mpgs_hc_payment_action')),
            'mpgs_hc_theme'          => Tools::getValue('mpgs_hc_theme', Configuration::get('mpgs_hc_theme')),
            'mpgs_hc_show_billing'   => Tools::getValue('mpgs_hc_show_billing',
                Configuration::get('mpgs_hc_show_billing') ?: 'HIDE'),
            'mpgs_hc_show_email'     => Tools::getValue('mpgs_hc_show_email',
                Configuration::get('mpgs_hc_show_email') ?: 'HIDE'),
            'mpgs_hs_active'         => Tools::getValue('mpgs_hs_active', Configuration::get('mpgs_hs_active')),
            'mpgs_hs_title'          => $hsTitle,
            'mpgs_hs_payment_action' => Tools::getValue('mpgs_hs_payment_action',
                Configuration::get('mpgs_hs_payment_action')),
            'mpgs_hs_3ds'            => Tools::getValue('mpgs_hs_3ds', Configuration::get('mpgs_hs_3ds')),

            'mpgs_mode'              => Tools::getValue('mpgs_mode', Configuration::get('mpgs_mode')),
            'mpgs_order_prefix'      => Tools::getValue('mpgs_order_prefix', Configuration::get('mpgs_order_prefix')),
            'mpgs_api_url'           => Tools::getValue('mpgs_api_url', Configuration::get('mpgs_api_url')),
            'mpgs_api_url_custom'    => Tools::getValue('mpgs_api_url_custom',
                Configuration::get('mpgs_api_url_custom')),
            'mpgs_lineitems_enabled' => Tools::getValue('mpgs_lineitems_enabled',
                Configuration::get('mpgs_lineitems_enabled') ?: "1"),
            'mpgs_webhook_url'       => Tools::getValue('mpgs_webhook_url', Configuration::get('mpgs_webhook_url')),
            'mpgs_logging_level'     => Tools::getValue('mpgs_logging_level',
                Configuration::get('mpgs_logging_level') ?: \Monolog\Logger::ERROR),

            'mpgs_merchant_id'    => Tools::getValue('mpgs_merchant_id', Configuration::get('mpgs_merchant_id')),
            'mpgs_api_password'   => Tools::getValue('mpgs_api_password', Configuration::get('mpgs_api_password')),
            'mpgs_webhook_secret' => Tools::getValue('mpgs_webhook_secret',
                Configuration::get('mpgs_webhook_secret') ?: null),

            'test_mpgs_merchant_id'    => Tools::getValue('test_mpgs_merchant_id',
                Configuration::get('test_mpgs_merchant_id')),
            'test_mpgs_api_password'   => Tools::getValue('test_mpgs_api_password',
                Configuration::get('test_mpgs_api_password')),
            'test_mpgs_webhook_secret' => Tools::getValue('test_mpgs_webhook_secret',
                Configuration::get('test_mpgs_webhook_secret') ?: null),
            'mpgs_hc_payment_method' => Tools::getValue('mpgs_hc_payment_method',
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
                        'label'   => $this->l('Enabled'),
                        'name'    => 'mpgs_hc_active',
                        'is_bool' => true,
                        'desc'    => '',
                        'values'  => array(
                            array(
                                'id'    => 'active_off',
                                'value' => true,
                                'label' => $this->l('Disabled'),
                            ),
                            array(
                                'id'    => 'active_on',
                                'value' => false,
                                'label' => $this->l('Enabled'),
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
                        'type'     => 'text',
                        'label'    => $this->l('Theme'),
                        'name'     => 'mpgs_hc_theme',
                        'required' => false,
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
        $apiOptions = array();
        $c = 0;
        foreach ($this->getApiUrls() as $url => $label) {
            $apiOptions[] = array(
                'id'    => 'api_'.$c,
                'value' => $url,
                'label' => $label,
            );
            $c++;
        }

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
                                'id'    => 'active_off',
                                'value' => true,
                                'label' => $this->l('Disabled'),
                            ),
                            array(
                                'id'    => 'active_on',
                                'value' => false,
                                'label' => $this->l('Enabled'),
                            ),
                        ),
                    ),
                    array(
                        'type'   => 'radio',
                        'name'   => 'mpgs_api_url',
                        'desc'   => $this->l(''),
                        'label'  => $this->l('API Endpoint'),
                        'values' => $apiOptions,
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->l('Custom API Endpoint'),
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
                                'id'    => 'active_off',
                                'value' => true,
                                'label' => $this->l('Disabled'),
                            ),
                            array(
                                'id'    => 'active_on',
                                'value' => false,
                                'label' => $this->l('Enabled'),
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
                                array('id' => \Monolog\Logger::DEBUG, 'name' => $this->l('Everything')),
                                array('id' => \Monolog\Logger::WARNING, 'name' => $this->l('Errors and Warning Only')),
                                array('id' => \Monolog\Logger::ERROR, 'name' => $this->l('Errors Only')),
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
                    array(
                        'type'     => 'text',
                        'label'    => $this->l('Custom Webhook Endpoint'),
                        'desc'     => $this->l('Not required. If left blank, the value defaults to: ').$this->context->link->getModuleLink($this->name,
                                'webhook', array(), true),
                        'name'     => 'mpgs_webhook_url',
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
     * Save form data.
     */
    protected function postProcess()
    {
        $form_values = $this->getAdminFormValues();

        // Handles normal fields
        foreach ($form_values as $key => $value) {
            if (is_array($value)) {
                continue;
            }

            $hiddenKeys = array(
                'mpgs_api_password',
                'test_mpgs_api_password',
                'mpgs_webhook_secret',
                'test_mpgs_webhook_secret',
            );

            if (in_array($key, $hiddenKeys)) {
                if (!$value) {
                    continue;
                }
            }

            Configuration::updateValue($key, $value);
        }

        // Handles translated fields
        $translatedFields = array(
            'mpgs_hc_title',
            'mpgs_hs_title',
        );
        $languages = Language::getLanguages(false);
        foreach ($translatedFields as $field) {
            $translatedValues = array();
            foreach ($languages as $lang) {
                if (Tools::getIsset($field.'_'.$lang['id_lang'])) {
                    $translatedValues[$lang['id_lang']] = Tools::getValue($field.'_'.$lang['id_lang']);
                }
            }
            Configuration::updateValue($field, $translatedValues);
        }

        $this->_html .= $this->displayConfirmation($this->l('Settings updated'));
        $this->enterprisedatasend();
        // Test the Gateway connection
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

        if ($order->payment !== self::PAYMENT_CODE) {
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

            $refund = new MpgsRefund();
            $refund->order_id = $order->id;
            $refund->total = $amount;
            $refund->transaction_id = $response['transaction']['id'];
            $refund->order_slip_id = $orderSlip->id;
            $refund->add();

        } catch (Exception $e) {
            $orderSlip->delete();
            Tools::redirectAdmin((new Link())->getAdminLink('AdminOrders', true, array(), array(
                'vieworder' => '',
                'id_order'  => $order->id,
            )));

            die();
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
        if ($this->active == false) {
            return '';
        }

        $order = new Order($params['id_order']);
        if ($order->payment != self::PAYMENT_CODE) {
            return '';
        }

        $isAuthorized = $order->current_state == Configuration::get('MPGS_OS_AUTHORIZED');
        $canVoid = $isAuthorized;
        $canCapture = $isAuthorized;
        $canRefund = $order->current_state == Configuration::get('PS_OS_PAYMENT');
        $canReview = $order->current_state == Configuration::get('MPGS_OS_REVIEW_REQUIRED');
        $canAction = $isAuthorized || $canVoid || $canCapture || $canRefund;

        $orderState = (int)$order->getCurrentState();
        $hidePartialRefundButton = true;

        // Assuming you have constants or configuration keys for "Refunded" and "Void" statuses
        $refundedStateId = $order->current_state == Configuration::get('PS_OS_REFUND');
        $voidStateId = $order->current_state == Configuration::get('PS_OS_CANCELED');
        $paymentStateId = $order->current_state == Configuration::get('PS_OS_PAYMENT');
        if($orderState == $paymentStateId){
            $hidePartialRefundButton = false;
        }
        // Check if the order status is either "Refunded" or "Void"
        if ($orderState == $refundedStateId || $orderState == $voidStateId) {
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
            'hidePartialRefundButton'=>  $hidePartialRefundButton ,
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

        if (Configuration::get('mpgs_hs_active') && Configuration::get('mpgs_gateway_validated')) {
            $methods[] = $this->getHostedSessionPaymentOption();
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
        $form = $this->generateHostedCheckoutForm();

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
    public function getApiEndpoint()
    {
        $endpoint = Configuration::get('mpgs_api_url');
        if (!$endpoint) {
            $endpoint = Configuration::get('mpgs_api_url_custom');
        }

        if (!$endpoint) {
            throw new Exception("API endpoint not specified.");
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

        return 'https://'.$this->getApiEndpoint().'/static/checkout/checkout.min.js?_='.$cacheBust;
    }

    /**
     * @return string
     * @throws Exception
     * https://mtf.gateway.mastercard.com/form/version/50/merchant/<MERCHANTID>/session.js
     */
    public function getHostedSession3DSUrl()
    {
        $cacheBust = (int)round(microtime(true));

        return 'https://'.$this->getApiEndpoint().'/static/threeDS/'.$this->get3DSLibVersion().'/three-ds.min.js?_='.$cacheBust;
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

        $items = $this->context->cart->getProducts(false, false, $this->context->country->id, true);
        $cartItems = array();

        $hasDelta = $deltaAmount > 0;

        /** @var Product $item */
        foreach ($items as $item) {
            $catyItem = array(
                'name'      => GatewayService::safe($item['name'], 127),
                'quantity'  => GatewayService::numeric($item['cart_quantity']),
                'sku'       => GatewayService::safe($item['reference'], 127),
                'unitPrice' => GatewayService::numeric($item['price_wt']),
            );

            if ($hasDelta && $item['cart_quantity']) {
                $hasDelta = false;
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
    public function upgrade_module_1_3_3()
    {
        $dbPrefix = _DB_PREFIX_;
        $mysqlEngine = _MYSQL_ENGINE_;
        $query = <<<EOT
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
    public function upgrade_module_1_3_6()
    {
        $dbPrefix = _DB_PREFIX_;
        $query = <<<EOT
        DROP TABLE IF EXISTS `{$dbPrefix}mpgs_payment_order_suffix`;
        EOT;

        return DB::getInstance()->execute($query);
    }

    /**
     * @return bool
     */
    public function upgrade_module_1_3_7()
    {
        $dbPrefix = _DB_PREFIX_;
        $mysqlEngine = _MYSQL_ENGINE_;
        $query = <<<EOT
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