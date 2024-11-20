# Changelog
All notable changes to this project will be documented in this file.

## [1.4.3] - 2024-11-21
### Changed
- Enhanced Compatibility: Compatible with PHP 8.1 & PrestaShop 8.1.x (tested with v8.1.7).
- The module has been updated to API version 84.

## [1.4.2] - 2024-07-06
### Changed
- The module has been updated to API version 81.

## [1.4.1] - 2024-02-26
### Changed
- The module has been updated to API version 78.
- Replaced the obsolete php-http/message-factory package with the actively maintained psr/http-factory package.
- Added nyholm/psr7 package.

### Enhancements
- User will be notified, if there is a new plugin version available on Git Hub.

## [1.4.0] - 2023-08-02
### Changed
- Enhanced Compatibility: Compatible with PHP 8.1 & Prestashop 8.0.x (tested with v8.0.4).
- The module has been updated to API version 73.
- Added the Checkout Interaction Model feature to the admin settings.
- The Hosted session payment method is no longer supported.

## [1.3.8] - 2022-04-20
### Fixed
- The products are removed from the customer's shopping cart if the payment fails.


## [1.3.7] - 2022-02-07
### Fixed
- The refund is failing if the Gateway Order ID Prefix field is longer than 41.
- It's impossible to void authorized transactions on the newest versions of Prestashop.
- Admin is redirected to the Order Listing instead of the Order View page after actions produced by the module.


## [1.3.5] - 2021-11-12
### Changed
- Add support for the "Enforce Unique Order Reference" and "Enforce Unique Merchant Transaction Reference" gateway features.
- Add 3DS2 support.

### Fixed
- A Security issue with access to some module folders.


## [1.1.0] - 2020-04-28


## [1.0.0] - 2019-06-19