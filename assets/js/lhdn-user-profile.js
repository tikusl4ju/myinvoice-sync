/**
 * LHDN User Profile TIN fields behaviour (admin profile and My Account).
 */
(function() {
    var checkbox = document.getElementById('lhdn_not_malaysian') || document.getElementById('lhdn_not_malaysian_myaccount');
    var tinFields = document.querySelectorAll('.lhdn-tin-fields, .lhdn-tin-fields-myaccount');
    var tinInput  = document.getElementById('lhdn_tin');
    var idTypeRadios = document.querySelectorAll('input[name="lhdn_id_type"]');
    var idValue   = document.getElementById('lhdn_id_value');

    if (tinInput) {
        tinInput.addEventListener('keypress', function(e) {
            if (e.key === ' ' || e.keyCode === 32) {
                e.preventDefault();
                return false;
            }
        });

        tinInput.addEventListener('paste', function(e) {
            e.preventDefault();
            var pastedText = (e.clipboardData || window.clipboardData).getData('text');
            this.value = pastedText.replace(/\s+/g, '').toUpperCase();
        });

        tinInput.addEventListener('input', function() {
            var v = this.value;
            var c = v.replace(/\s+/g, '').toUpperCase();
            if (v !== c) {
                this.value = c;
            }
        });
    }

    if (checkbox) {
        function toggleFields() {
            tinFields.forEach(function(field) {
                field.style.display = checkbox.checked ? 'none' : '';
            });

            if (checkbox.checked) {
                if (tinInput) {
                    tinInput.value = '';
                }
                idTypeRadios.forEach(function(r) {
                    r.checked = false;
                });
                if (idValue) {
                    idValue.value = '';
                }
            }
        }

        checkbox.addEventListener('change', toggleFields);
        toggleFields();
    }
})();

