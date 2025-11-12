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

function mpgsLiveFields() {
    $("#mpgs_merchant_id").parents('.form-group').show();
    $("#mpgs_api_password").parents('.form-group').show();
    $("#mpgs_webhook_secret").parents('.form-group').show();
    $("#test_mpgs_merchant_id").parents('.form-group').hide();
    $("#test_mpgs_api_password").parents('.form-group').hide();
    $("#test_mpgs_webhook_secret").parents('.form-group').hide();
}

function mpgsTestFields() {
    $("#mpgs_merchant_id").parents('.form-group').hide();
    $("#mpgs_api_password").parents('.form-group').hide();
    $("#mpgs_webhook_secret").parents('.form-group').hide();
    $("#test_mpgs_merchant_id").parents('.form-group').show();
    $("#test_mpgs_api_password").parents('.form-group').show();
    $("#test_mpgs_webhook_secret").parents('.form-group').show();
}

function mgMerchantFields() {
    $("#mpgs_mi_merchant_name").parents('.form-group').show();
    $("#mpgs_mi_address_line1").parents('.form-group').show();
    $("#mpgs_mi_address_line2").parents('.form-group').show();
    $("#mpgs_mi_postalcode").parents('.form-group').show();
    $("#mpgs_mi_country").parents('.form-group').show();
    $("#mpgs_mi_email").parents('.form-group').show();
    $("#mpgs_mi_phone").parents('.form-group').show();
    $("#mpgs_mi_logo-name").parents('.form-group').show();
}

function mgTestMerchantFields() {
    $("#mpgs_mi_merchant_name").parents('.form-group').hide();
    $("#mpgs_mi_address_line1").parents('.form-group').hide();
    $("#mpgs_mi_address_line2").parents('.form-group').hide();
    $("#mpgs_mi_postalcode").parents('.form-group').hide();
    $("#mpgs_mi_country").parents('.form-group').hide();
    $("#mpgs_mi_email").parents('.form-group').hide();
    $("#mpgs_mi_phone").parents('.form-group').hide();
    $("#mpgs_mi_logo-name").parents('.form-group').hide();
}

$(document).ready(function() {
    var value = 0;
    value = $('input[name=mpgs_mode]:checked').val();

    if (value === "1") {
        mpgsLiveFields();
    } else {
        mpgsTestFields();
    }
    $('input[name=mpgs_mode]').on('change', function() {
        value = $('input[name=mpgs_mode]:checked').val();

        if (value === "1") {
            mpgsLiveFields();
        } else {
            mpgsTestFields();
        }
    });

    var value = 0;
    value = $('input[name=mpgs_mi_active]:checked').val();
    
    if (value === "1") {
        mgMerchantFields();
    } else {
        mgTestMerchantFields();
    }
    $('input[name=mpgs_mi_active]').on('change', function() {
        value = $('input[name=mpgs_mi_active]:checked').val();

        if (value === "1") {
            mgMerchantFields();
        } else {
            mgTestMerchantFields();
        }
    });
});
