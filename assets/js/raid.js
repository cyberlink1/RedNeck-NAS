// raid view specific behaviors and helpers

// form confirmations for add-spare and fail actions
document.addEventListener('DOMContentLoaded', function() {
    var addForm = document.getElementById('addRaidForm');
    if (addForm) {
        addForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var isSpare = addForm.closest('.modal')?.dataset?.spare === '1';
            var msg = isSpare ? 'Add selected disk as spare?' : 'Add selected disk to RAID?  This will start a rebuild.';
            showConfirmation(msg, function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = isSpare ? 'add_spare' : 'add_member';
                inp.value = '1';
                addForm.appendChild(inp);
                addForm.submit();
            });
        });
    }
    var failForm = document.getElementById('failRaidForm');
    if (failForm) {
        failForm.addEventListener('submit', function(e) {
            e.preventDefault();
            showConfirmation('Mark the selected member as failed and remove it?', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'fail_member';
                inp.value = '1';
                failForm.appendChild(inp);
                failForm.submit();
            });
        });
    }

    // buttons that submit forms with remove_raid name need confirmation
    var raidRemoveButtons = document.querySelectorAll('button[name="remove_raid"]');
    raidRemoveButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Stop and remove selected RAID array? Data on array will be lost.', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = btn.name;
                inp.value = btn.value || '';
                btn.form.appendChild(inp);
                btn.form.action = window.location.pathname + window.location.search;
                btn.form.submit();
            });
        });
    });

    // if the form uses a free-form device list convert it to hidden inputs
    var raidForm = document.getElementById('raidForm');
    if (raidForm) {
        raidForm.addEventListener('submit', function(e) {
            var devicesInput = document.getElementById('raidDevices');
            if (devicesInput) {
                var container = document.getElementById('deviceFields');
                container.innerHTML = '';
                var text = devicesInput.value.trim();
                if (text) {
                    var parts = text.split(/[,\s]+/);
                    parts.forEach(function(dev) {
                        if (dev) {
                            var inp = document.createElement('input');
                            inp.type = 'hidden';
                            inp.name = 'devices[]';
                            inp.value = dev;
                            container.appendChild(inp);
                        }
                    });
                }
            }
        });
    }
});

// row click handler to load raid info (may reuse showInfo helper)
(function() {
    var raidTable = document.getElementById('raidTable');
    if (raidTable) {
        raidTable.querySelectorAll('tbody tr').forEach(function(row) {
            row.addEventListener('click', function() {
                var dev = row.dataset.dev || '';
                fetchAuth('views/raid.php?ajax=1&raid=' + encodeURIComponent(dev))
                    .then(function(resp) { return resp.text(); })
                    .then(function(html) { showInfo(html); })
                    .catch(function(err) { console.error('raid AJAX error', err); });
            });
        });
    }
})();
