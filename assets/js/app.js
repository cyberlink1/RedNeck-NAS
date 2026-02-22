// General JS helpers
console.log('App script loaded');

// helper used by both the modal submit interceptor and the
// confirmation callbacks; posts the form by AJAX and refreshes the info
// modal contents with whatever HTML the server returns.
function submitDiskFormAjax(form) {
    var data = new FormData(form);
    if (form._lastSubmitName) {
        data.append(form._lastSubmitName, form._lastSubmitValue);
    }
    data.append('ajax', '1');
    fetch('views/disks.php', { method: 'POST', body: data })
        .then(function(resp) { return resp.text(); })
        .then(function(newHtml) {
            showInfo(newHtml);
        })
        .catch(function(err) {
            console.error('modal form ajax error', err);
        });
}

function attachDiskHandlers(root) {
    root = root || document;
    var deletePartBtn = root.querySelector('#btnDeletePart');
    if (deletePartBtn) {
        deletePartBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Delete the specified partition? This is destructive.', function() {
                // add the button parameter then submit via AJAX so the
                // page underneath isn’t replaced
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = deletePartBtn.name;
                inp.value = deletePartBtn.value || '';
                deletePartBtn.form.appendChild(inp);
                submitDiskFormAjax(deletePartBtn.form);
            });
        });
    }
    var wipeBtn = root.querySelector('#btnWipe');
    if (wipeBtn) {
        wipeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Wipe the selected disk (GPT table and superblocks)? This cannot be undone.', function() {
                submitDiskFormAjax(wipeBtn.form);
            });
        });
    }
}

function showInfo(html) {
    var modal = document.getElementById('infoModal');
    if (!modal) return;
    var body = modal.querySelector('.modal-body');
    body.innerHTML = html;
    // attach handlers for any form buttons inside (delete/wipe requires confirm)
    attachDiskHandlers(body);

    // convert any forms in the modal into AJAX submissions so the surrounding
    // page isn’t replaced with card HTML; after a POST we simply refresh the
    // modal contents with the updated response.
    body.querySelectorAll('form').forEach(function(form) {
        // remember which submit button was clicked so its name/value can be
        // included (FormData doesn’t automatically include the button unless
        // it’s passed to the constructor).  this fixes the “create partition”
        // action not being seen on AJAX submits.
        form.querySelectorAll('button[type=submit]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                form._lastSubmitName = btn.name;
                form._lastSubmitValue = btn.value || '';
            });
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var data = new FormData(form);
            if (form._lastSubmitName) {
                data.append(form._lastSubmitName, form._lastSubmitValue);
            }
            data.append('ajax', '1');
            fetch('views/disks.php', { method: 'POST', body: data })
                .then(function(resp) { return resp.text(); })
                .then(function(newHtml) {
                    showInfo(newHtml);
                })
                .catch(function(err) {
                    console.error('modal form ajax error', err);
                });
        });
    });

    // reuse existing bootstrap.Modal instance to avoid stacking backdrops when
    // the modal is already visible.
    var bsModal = bootstrap.Modal.getInstance(modal) || new bootstrap.Modal(modal);
    if (!modal.classList.contains('show')) {
        bsModal.show();
    }
}

function showConfirmation(text, onOk, onCancel) {
    // if info modal is open and currently shown, hide it first and only
    // display the confirmation once the preview has completely closed.  this
    // avoids the common problem of the confirm dialog appearing behind the
    // still‑visible info modal/backdrop when actions are triggered from the
    // preview window (e.g. wipe/delete buttons).
    var info = document.getElementById('infoModal');
    if (info) {
        var iModal = bootstrap.Modal.getInstance(info);
        if (iModal && info.classList.contains('show')) {
            var handler = function() {
                info.removeEventListener('hidden.bs.modal', handler);
                actuallyShowConfirm();
            };
            info.addEventListener('hidden.bs.modal', handler);
            iModal.hide();
            return;
        }
    }
    actuallyShowConfirm();

    function actuallyShowConfirm() {
        var modal = document.getElementById('confirmModal');
        if (!modal) return;
        var body = modal.querySelector('.modal-body');
        var okBtn = modal.querySelector('.btn-ok');
        var cancelBtn = modal.querySelector('.btn-cancel');
        // allow simple HTML (e.g. <br>) in messages
        body.innerHTML = text;
        // attach handlers inside modal content if any
        attachDiskHandlers(body);
        okBtn.onclick = function() {
            var bs = bootstrap.Modal.getInstance(modal);
            bs.hide();
            if (onOk) {
                // delay slightly to ensure hide animation starts
                setTimeout(onOk, 10);
            }
        };
        cancelBtn.onclick = function() { if (onCancel) onCancel(); var bs = bootstrap.Modal.getInstance(modal); bs.hide(); };
        var bsModal = new bootstrap.Modal(modal);
        bsModal.show();
    }
}

// convert comma or space separated device string into hidden inputs before submit
window.addEventListener('DOMContentLoaded', function() {
    console.log('DOMContentLoaded handler fired');
    var form = document.getElementById('raidForm');
    if (form) {
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
    // end if(form) block
    }
});

// disk table row info/selection (run regardless of DOMContentLoaded state)
(function() {
    var diskTable = document.getElementById('diskTable');
    console.log('diskTable element', diskTable);
    if (diskTable) {
        diskTable.querySelectorAll('tbody tr').forEach(function(row) {
            row.addEventListener('click', function() {
                var dev = row.dataset.dev || '';
                console.log('disk row clicked', dev);
                // request card HTML via AJAX
                fetch('views/disks.php?ajax=1&disk=' + encodeURIComponent(dev))
                    .then(function(resp) { return resp.text(); })
                    .then(function(html) {
                        showInfo(html);
                    })
                    .catch(function(err) {
                        console.error('AJAX error', err);
                    });
            });
        });
    }
})();

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
