document.addEventListener('DOMContentLoaded', function () {
    var paymentMethodSelect = document.getElementById('mpgs_hc_payment_method');
    var fieldset = document.getElementById('fieldset_1_1');
    // Function to toggle the visibility of the fieldset
    function toggleFieldsetVisibility() {
        if (paymentMethodSelect.value === 'REDIRECT') {
            fieldset.style.display = 'block';  // Show the fieldset
        } else {
            fieldset.style.display = 'none';   // Hide the fieldset
        }
    }

    // Initial check on page load to set the visibility
    toggleFieldsetVisibility();

    // Add an event listener to trigger the function when the dropdown value changes
    paymentMethodSelect.addEventListener('change', toggleFieldsetVisibility);
});

function mpgsDeleteLogo() {
    document.getElementById('mpgs_delete_logo').value = 1;
    var preview = document.getElementById('mpgs-logo-preview');
    if (preview) {
        preview.style.display = 'none';
    }
    return false;
}
