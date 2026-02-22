// General JS helpers
console.log('App script loaded');

function showConfirmation(text, onOk, onCancel) {
    var modal = document.getElementById('confirmModal');
    if (!modal) return;
    var body = modal.querySelector('.modal-body');
    var okBtn = modal.querySelector('.btn-ok');
    var cancelBtn = modal.querySelector('.btn-cancel');
    // allow simple HTML (e.g. <br>) in messages
    body.innerHTML = text;
    okBtn.onclick = function() { if (onOk) onOk(); var bs = bootstrap.Modal.getInstance(modal); bs.hide(); };
    cancelBtn.onclick = function() { if (onCancel) onCancel(); var bs = bootstrap.Modal.getInstance(modal); bs.hide(); };
    var bsModal = new bootstrap.Modal(modal);
    bsModal.show();
}

// convert comma or space separated device string into hidden inputs before submit
window.addEventListener('DOMContentLoaded', function() {
    var form = document.getElementById('raidForm');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        var devicesInput = document.getElementById('raidDevices');
        if (devicesInput) {
            // remove existing hidden fields
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

    // attach confirmation dialogs to action buttons
    var removeLvBtn = document.getElementById('btnRemoveLv');
    if (removeLvBtn) {
        removeLvBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Remove selected LV? This will destroy its data.', function() {
                // ensure the button name/value is included since form.submit() omits it
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = removeLvBtn.name;
                inp.value = removeLvBtn.value || '';
                removeLvBtn.form.appendChild(inp);
                removeLvBtn.form.submit();
            });
        });
    }
    var removeVgBtn = document.getElementById('btnRemoveVg');
    if (removeVgBtn) {
        removeVgBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Remove selected VG? All contained LVs will be lost.', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = removeVgBtn.name;
                inp.value = removeVgBtn.value || '';
                removeVgBtn.form.appendChild(inp);
                removeVgBtn.form.submit();
            });
        });
    }
    var formatLvBtn = document.getElementById('btnFormatLv');
    if (formatLvBtn) {
        formatLvBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Format selected LV? All data will be erased.', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = formatLvBtn.name;
                inp.value = formatLvBtn.value || '';
                formatLvBtn.form.appendChild(inp);
                formatLvBtn.form.submit();
            });
        });
    }
    var removeRaidBtn = document.getElementById('btnRemoveRaid');
    if (removeRaidBtn) {
        removeRaidBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Stop and remove selected RAID array? Data on array will be lost.', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = removeRaidBtn.name;
                inp.value = removeRaidBtn.value || '';
                removeRaidBtn.form.appendChild(inp);
                removeRaidBtn.form.submit();
            });
        });
    }

    // mount/unmount confirmation on mounts.php
    var mountBtn = document.getElementById('btnMount');
    if (mountBtn) {
        mountBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Mount the selected logical volume?\nThis will create or use /export/<em>subdir</em>.', function() {
                mountBtn.form.submit();
            });
        });
    }
    var umountBtn = document.getElementById('btnUmount');
    if (umountBtn) {
        umountBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Unmount the selected export?\nAny users accessing it will be disconnected.', function() {
                umountBtn.form.submit();
            });
        });
    }

    // NFS export removal confirmation (buttons generated per row)
    var exportButtons = document.querySelectorAll('button[name="remove_export"]');
    exportButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Remove this NFS export?', function() {
                // button name/value already in form
                btn.form.submit();
            });
        });
    });
});

// display any message that was provided by PHP via a hidden element
window.addEventListener('DOMContentLoaded', function() {
    var msgEl = document.getElementById('initialMessage');
    if (msgEl) {
        var text = msgEl.innerHTML;
        if (text) {
            showConfirmation(text);
        }
        msgEl.parentNode.removeChild(msgEl);
    }
});
