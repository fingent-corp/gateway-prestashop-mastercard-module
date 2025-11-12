{if $latest_release.available && $latest_release.version > $module_version}
    <div class="alert alert-info">
        A new version ({$latest_release.version}) of the module is now available! Please refer to the <a href="https://mpgs.fingent.wiki/target/prestashop-mastercard-payment-gateway-services/release-notes/" target="_blank">Release Notes</a> section for information about its compatibility and features.
    </div>
{/if}
