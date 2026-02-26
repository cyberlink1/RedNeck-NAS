// General JS helpers
// App script loaded (debug logs removed)

// spinner handling – overlay is hidden by default, toggled by adding/removing
// the "show" class (see assets/css/style.css).  In addition we hook the
// document-level submit event so any page navigation displays the overlay.
function showSpinner() {
    var o = document.getElementById('spinnerOverlay');
    if (o) o.classList.add('show');
}
function hideSpinner() {
    var o = document.getElementById('spinnerOverlay');
    if (o) o.classList.remove('show');
}
document.addEventListener('submit', function(e) {
    // when any form is actually submitted (including via JS), show the spinner.
    // however confirmation handlers call preventDefault(), and they run before
    // this listener (document was bound earlier) so the overlay would flash even
    // when the submission is cancelled.  Delay execution to the next tick and
    // only display if the event wasn't prevented.
    setTimeout(function() {
        if (!e.defaultPrevented) {
            showSpinner();
        }
    }, 0);
});

// wrap fetch calls and handle authentication failures
function fetchAuth(input, init) {
    return fetch(input, init).then(function(resp) {
        if (resp.status === 401) {
            // session expired – redirect to login page so the user can re‑auth.
            // the 401 status is returned by require_login() for AJAX requests.
            window.location = 'login.php';
            return Promise.reject(new Error('unauthorized'));
        }
        return resp;
    });
}

// cache of filesystem types read from the initial page; used as a fallback
// if a modal loses its options after an AJAX refresh of the info modal.
var cachedFsTypes = [];
document.addEventListener('DOMContentLoaded', function() {
    /* dark mode switch: checkbox styled as a Bootstrap form-switch */
    var darkToggle = document.getElementById('darkModeToggle');
    function swapBgClasses(enable) {
        // when dark mode is active we want <body> to carry the custom
        // bg-main class (not Bootstrap's bg-dark).  clear any light/dark
        // utility classes so the custom colour can take effect.
        if (enable) {
            document.body.classList.remove('bg-light', 'bg-dark');
            document.body.classList.add('bg-main');
        } else {
            document.body.classList.remove('bg-main', 'bg-dark');
            document.body.classList.add('bg-light');
        }
    }

    function applyStoredDarkMode() {
        var val = localStorage.getItem('darkMode');
        var enabled = val === '1';
        if (enabled) document.body.classList.add('dark-mode');
        else document.body.classList.remove('dark-mode');
        swapBgClasses(enabled);
        if (darkToggle) darkToggle.checked = enabled;
    }
    if (darkToggle) {
        darkToggle.addEventListener('change', function() {
            var enabled = !!darkToggle.checked;
            if (enabled) document.body.classList.add('dark-mode');
            else document.body.classList.remove('dark-mode');
            swapBgClasses(enabled);
            localStorage.setItem('darkMode', enabled ? '1' : '0');
        });
    }
    applyStoredDarkMode();

    var fsel = document.querySelector('select[name="fstype"]');
    if (fsel) {
        cachedFsTypes = [].slice.call(fsel.options)
            .map(function(o){ return o.value; })
            .filter(function(v){ return v; });
        console.log('cached filesystem types', cachedFsTypes);
    }

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

    // volume-group modal button (first handler block ensures binding before event fires)
    var showVgBtn = document.getElementById('btnShowVgModal');
    if (showVgBtn) {
        console.log('attaching vg modal opener');
        showVgBtn.addEventListener('click', function() {
            new bootstrap.Modal(document.getElementById('vgModal')).show();
        });
    }
    var showLvBtn = document.getElementById('btnShowLvModal');
    if (showLvBtn) {
        showLvBtn.addEventListener('click', function() {
            new bootstrap.Modal(document.getElementById('lvModal')).show();
        });
    }
    var createVgBtn = document.getElementById('btnOpenCreateVg');
    if (createVgBtn) {
        createVgBtn.addEventListener('click', function() {
            var vgModal = document.getElementById('vgModal');
            var inst = bootstrap.Modal.getInstance(vgModal);
            if (inst) inst.hide();
            new bootstrap.Modal(document.getElementById('createVgModal')).show();
        });
    }
    var openCreateLv = document.getElementById('btnOpenCreateLv');
    if (openCreateLv) {
        openCreateLv.addEventListener('click', function() {
            var lvModal = document.getElementById('lvModal');
            var inst = bootstrap.Modal.getInstance(lvModal);
            if (inst) inst.hide();
            new bootstrap.Modal(document.getElementById('createLvModal')).show();
        });
    }
    // thin pool creation is now initiated from the VG modal
    var openThinPoolVg = document.getElementById('btnOpenThinPoolVg');
    if (openThinPoolVg) {
        openThinPoolVg.addEventListener('click', function() {
            var selected = [];
            document.querySelectorAll('#vgModal .vg-checkbox:checked').forEach(function(cb) {
                if (cb.value) selected.push(cb.value);
            });
            if (selected.length === 0) {
                showConfirmation('Please select one volume group to create a thin pool in.');
                return;
            }
            if (selected.length > 1) {
                showConfirmation('Please select only one volume group.');
                return;
            }
            var vgModal = document.getElementById('vgModal');
            var inst = bootstrap.Modal.getInstance(vgModal);
            if (inst) inst.hide();
            // populate hidden field
            var tpvg = document.querySelector('#thinPoolModal input[name="tp_vg"]');
            if (tpvg) tpvg.value = selected[0];
            new bootstrap.Modal(document.getElementById('thinPoolModal')).show();
        });
    }
    var openFormatLv = document.getElementById('btnOpenFormatLv');
    if (openFormatLv) {
        openFormatLv.addEventListener('click', function() {
            var selected = Array.from(document.querySelectorAll('#lvModal tbody input.lv-checkbox:checked')).map(function(cb){return cb.value;});
            if (selected.length === 0) {
                showConfirmation('Please select at least one logical volume to format.');
                return;
            }
            var listContainer = document.getElementById('formatList');
            listContainer.innerHTML = '<label class="form-label">Volumes to format</label>';
            selected.forEach(function(lv) {
                var div = document.createElement('div');
                div.textContent = lv;
                var hid = document.createElement('input');
                hid.type = 'hidden'; hid.name = 'lvs[]'; hid.value = lv;
                div.appendChild(hid);
                listContainer.appendChild(div);
            });
            var lvModal = document.getElementById('lvModal');
            var inst = bootstrap.Modal.getInstance(lvModal);
            if (inst) inst.hide();
            new bootstrap.Modal(document.getElementById('formatLvModal')).show();
        });
    }
    var openRemoveLv = document.getElementById('btnOpenRemoveLv');
    if (openRemoveLv) {
        openRemoveLv.addEventListener('click', function() {
            var selected = Array.from(document.querySelectorAll('#lvModal tbody input.lv-checkbox:checked')).map(function(cb){return cb.value;});
            if (selected.length === 0) {
                showConfirmation('Please select at least one logical volume to remove.');
                return;
            }
            var form = document.getElementById('removeLvForm');
            // adjust modal title for plural
            var modal = document.getElementById('removeLvModal');
            if (modal) {
                var title = modal.querySelector('.modal-title');
                if (title) {
                    title.textContent = selected.length > 1 ? 'Remove Logical Volumes' : 'Remove Logical Volume';
                }
            }
            var sel = form.querySelector('select[name="lv_select"]');
            var listContainer = form.querySelector('#removeLvList');
            // reset state from previous use
            if (listContainer) listContainer.innerHTML = '';
            if (sel) {
                sel.value = '';
                sel.closest('.mb-3').style.display = '';
                sel.required = true;
                sel.disabled = false;
            }
            // remove any existing hidden lvs[] inputs
            form.querySelectorAll('input[name="lvs[]"]').forEach(function(i){ i.remove(); });

            // always create hidden inputs for every selected LV, even if only one
            selected.forEach(function(lv){
                var hid = document.createElement('input');
                hid.type = 'hidden'; hid.name = 'lvs[]'; hid.value = lv;
                form.appendChild(hid);
            });
            if (selected.length === 1) {
                // single choice, keep dropdown for clarity and set its value
                if (sel) {
                    sel.value = selected[0];
                    sel.required = true;
                    sel.disabled = false;
                }
            } else {
                // multiple: hide dropdown and show list of volumes
                if (sel) {
                    sel.closest('.mb-3').style.display = 'none';
                    sel.required = false;
                    sel.disabled = true;
                }
                if (listContainer) {
                    var label = document.createElement('label');
                    label.className = 'form-label';
                    label.textContent = 'Volumes to remove';
                    listContainer.appendChild(label);
                    selected.forEach(function(lv){
                        var div = document.createElement('div');
                        div.textContent = lv;
                        listContainer.appendChild(div);
                    });
                }
            }

            var lvModal = document.getElementById('lvModal');
            var inst = bootstrap.Modal.getInstance(lvModal);
            if (inst) inst.hide();
            new bootstrap.Modal(document.getElementById('removeLvModal')).show();
        });
    }
    // toggle checkbox when clicking on a row in the LV table
    var lvModalElt = document.getElementById('lvModal');
    if (lvModalElt) {
        lvModalElt.addEventListener('shown.bs.modal', function() {
            document.querySelectorAll('#lvModal tbody tr').forEach(function(row) {
                row.addEventListener('click', function(e) {
                    if (e.target.type !== 'checkbox') {
                        var cb = row.querySelector('input.lv-checkbox');
                        if (cb) cb.checked = !cb.checked;
                    }
                });
            });
        });
    }

    // make mount table rows clickable for editing
    var mountTable = document.getElementById('mountTable');
    if (mountTable) {
        document.querySelectorAll('#mountTable tbody tr').forEach(function(row) {
            row.addEventListener('click', function(e) {
                // ignore clicks on buttons/forms inside the row (eg unmount)
                if (e.target.closest('button') || e.target.closest('form')) {
                    return;
                }
                var dev = row.getAttribute('data-dev');
                var pt  = row.getAttribute('data-pt');
                var opts = row.getAttribute('data-opts') || '';
                var inFstab = row.getAttribute('data-infstab') === '1';
                var form = document.getElementById('editMountForm');
                if (!form) return;
                form.device.value = dev;
                // update title with device
                var titleElt = document.getElementById('editMountModalTitle');
                if (titleElt) {
                    titleElt.textContent = 'Edit mount ' + dev;
                }
                // strip leading /export/ from mount point for display
                form.mount_point.value = pt.replace(/^\/export\//, '');
                form.mount_boot.checked = inFstab;
                // set option checkboxes
                form.querySelectorAll('input[name="mount_opts[]"]').forEach(function(cb) {
                    cb.checked = opts.split(',').includes(cb.value);
                });
                new bootstrap.Modal(document.getElementById('editMountModal')).show();
            });
        });
    }

    // confirmation for export removal buttons
    document.querySelectorAll('.btn-remove-export').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var form = btn.closest('form');
            showConfirmation('Remove this export entry? This will update /etc/exports.', function() {
                form.submit();
            });
        });
    });
    // confirmation for samba share removal buttons
    document.querySelectorAll('.btn-remove-share').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var form = btn.closest('form');
            showConfirmation('Remove this Samba share? This will update smb.conf.', function() {
                form.submit();
            });
        });
    });
    // confirm format and removal forms
    var formatLvForm = document.getElementById('formatLvForm');
    if (formatLvForm) {
        formatLvForm.addEventListener('submit', function(e) {
            e.preventDefault();
            showConfirmation('Format the selected logical volume? Any data on it will be lost.', function() {
                // add hidden field to indicate which action we're performing since
                // programmatic submit() does not include the button name/value.
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'format_lv';
                inp.value = '1';
                formatLvForm.appendChild(inp);
                showSpinner();
                formatLvForm.submit();
            });
        });
    }
    var removeLvForm = document.getElementById('removeLvForm');
    if (removeLvForm) {
        removeLvForm.addEventListener('submit', function(e) {
            e.preventDefault();
            // determine how many volumes will be removed for better wording
            var count = removeLvForm.querySelectorAll('input[name="lvs[]"]').length;
            if (count === 0) {
                var sel = removeLvForm.querySelector('select[name="lv_select"]');
                if (sel && sel.value) count = 1;
            }
            var msg = count > 1
                    ? 'Remove the ' + count + ' selected logical volumes? This is irreversible.'
                    : 'Remove the selected logical volume? This is irreversible.';
            showConfirmation(msg, function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'remove_lv';
                inp.value = '1';
                removeLvForm.appendChild(inp);
                showSpinner();
                removeLvForm.submit();
            });
        });
    }
    // extend / rename / convert workflows
    var openExtendLv = document.getElementById('btnOpenExtendLv');
    var openRenameLv = document.getElementById('btnOpenRenameLv');
    var openConvertLv = document.getElementById('btnOpenConvertLv');
    function pickSingleLv() {
        var selected = [];
        document.querySelectorAll('#lvModal .lv-checkbox:checked').forEach(function(cb){
            if (cb.value) selected.push(cb.value);
        });
        return selected;
    }
    if (openExtendLv) {
        openExtendLv.addEventListener('click', function() {
            var sel = pickSingleLv();
            if (sel.length === 0) {
                showConfirmation('Please select one logical volume to extend.');
                return;
            }
            if (sel.length > 1) {
                showConfirmation('Please select only one logical volume.');
                return;
            }
            var form = document.getElementById('extendLvForm');
            if (form) {
                form.querySelector('input[name="lv_select_extend"]').value = sel[0];
            }
            var lvModal = document.getElementById('lvModal');
            var inst = bootstrap.Modal.getInstance(lvModal);
            if (inst) inst.hide();
            new bootstrap.Modal(document.getElementById('extendLvModal')).show();
        });
    }
    if (openRenameLv) {
        openRenameLv.addEventListener('click', function() {
            var sel = pickSingleLv();
            if (sel.length === 0) {
                showConfirmation('Please select one logical volume to rename.');
                return;
            }
            if (sel.length > 1) {
                showConfirmation('Please select only one logical volume.');
                return;
            }
            var form = document.getElementById('renameLvForm');
            if (form) {
                form.querySelector('input[name="lv_select_rename"]').value = sel[0];
            }
            var lvModal = document.getElementById('lvModal');
            var inst = bootstrap.Modal.getInstance(lvModal);
            if (inst) inst.hide();
            new bootstrap.Modal(document.getElementById('renameLvModal')).show();
        });
    }
    if (openConvertLv) {
        openConvertLv.addEventListener('click', function() {
            var sel = pickSingleLv();
            if (sel.length === 0) {
                showConfirmation('Please select one logical volume to convert.');
                return;
            }
            if (sel.length > 1) {
                showConfirmation('Please select only one logical volume.');
                return;
            }
            // determine current type from table row
            var currentType = '';
            var cb = document.querySelector('#lvModal .lv-checkbox:checked');
            if (cb) {
                var tr = cb.closest('tr');
                if (tr && tr.cells.length > 3) {
                    currentType = tr.cells[3].textContent.trim();
                }
            }
            var form = document.getElementById('convertLvForm');
            if (form) {
                form.querySelector('input[name="lv_select_convert"]').value = sel[0];
                var selBox = form.querySelector('select[name="lv_convert_type"]');
                if (selBox && currentType) {
                    // disable option matching currentType (case-sensitive
                    // match to be safe)
                    Array.from(selBox.options).forEach(function(opt) {
                        if (opt.value === currentType) {
                            opt.disabled = true;
                        } else {
                            opt.disabled = false;
                        }
                    });
                }
            }
            var lvModal = document.getElementById('lvModal');
            var inst = bootstrap.Modal.getInstance(lvModal);
            if (inst) inst.hide();
            new bootstrap.Modal(document.getElementById('convertLvModal')).show();
        });
    }
    var extendLvForm = document.getElementById('extendLvForm');
    if (extendLvForm) {
        extendLvForm.addEventListener('submit', function(e) {
            e.preventDefault();
            showConfirmation('Extend the selected logical volume?', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'extend_lv'; inp.value = '1';
                extendLvForm.appendChild(inp);
                showSpinner();
                extendLvForm.submit();
            });
        });
    }
    var renameLvForm = document.getElementById('renameLvForm');
    if (renameLvForm) {
        renameLvForm.addEventListener('submit', function(e) {
            e.preventDefault();
            showConfirmation('Rename the logical volume?', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'rename_lv'; inp.value = '1';
                renameLvForm.appendChild(inp);
                showSpinner();
                renameLvForm.submit();
            });
        });
    }
    var convertLvForm = document.getElementById('convertLvForm');
    if (convertLvForm) {
        convertLvForm.addEventListener('submit', function(e) {
            e.preventDefault();
            showConfirmation('Convert the logical volume type? This may involve data movement.', function() {
                var inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'convert_lv'; inp.value = '1';
                convertLvForm.appendChild(inp);
                showSpinner();
                convertLvForm.submit();
            });
        });
    }
    // snapshot logic (list/selection table + separate create modal)
    // helper for human‑friendly size display
    function hrSize(bytes) {
        var b = parseFloat(bytes.toString().replace(/B$/, ''));
        if (isNaN(b)) return bytes;
        var units = ['B','K','M','G','T','P'];
        var u = 0;
        while (b >= 1024 && u < units.length-1) {
            b /= 1024;
            u++;
        }
        return b.toFixed( (u>0)?1:0 ) + units[u];
    }
    function loadSnapshots(lv) {
        var tableBody = document.getElementById('snapListBody');
        if (!tableBody) return;
        tableBody.innerHTML = '';
        return fetchAuth('dashboard.php?view=lvm&ajax=list_snaps&lv=' + encodeURIComponent(lv))
            .then(r => r.text())
            .then(txt => {
                // quick sanity check: if the response looks like a full HTML page,
                // bail out rather than dumping markup into the table.
                if (/<!doctype html|<html/i.test(txt)) {
                    console.warn('snapshot list AJAX returned HTML, likely a login redirect');
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td colspan="4"><em>failed to load snapshots (maybe not logged in?)</em></td>';
                    tableBody.appendChild(tr);
                    return;
                }
                txt.split('\n').forEach(function(line) {
                    line = line.trim();
                    if (line === '') return;
                    var parts = line.split('|');
                    var path = parts[0] || '';
                    var size = parts[1] || '';
                    var time = parts[2] || '';
                    // convert size to human units
                    var displaySize = hrSize(size);
                    var tr = document.createElement('tr');
                    tr.innerHTML = '<td><input type="radio" name="snap_select" value="' +
                        path + '"></td>' +
                        '<td>' + path + '</td>' +
                        '<td>' + displaySize + '</td>' +
                        '<td>' + time + '</td>';
                    tableBody.appendChild(tr);
                });
            });
    }
    document.querySelectorAll('.snapshot-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var lv = btn.getAttribute('data-lv');
            var form = document.getElementById('snapshotForm');
            if (form) {
                form.snap_lv.value = lv;
            }
            var createForm = document.getElementById('createSnapshotForm');
            if (createForm) {
                createForm.snap_lv.value = lv;
            }
            loadSnapshots(lv);
            var snapModal = document.getElementById('snapshotModal');
            var inst = bootstrap.Modal.getInstance(snapModal);
            if (inst) inst.hide();
            new bootstrap.Modal(snapModal).show();
        });
    });
    var snapForm = document.getElementById('snapshotForm');
    if (snapForm) {
        snapForm.addEventListener('submit', function(e) {
            e.preventDefault();
        });
    }
    document.getElementById('btnOpenCreateSnapshot')?.addEventListener('click', function() {
        var snapModal = document.getElementById('snapshotModal');
        var inst = bootstrap.Modal.getInstance(snapModal);
        if (inst) inst.hide();
        new bootstrap.Modal(document.getElementById('createSnapshotModal')).show();
    });
    var createSnapshotForm = document.getElementById('createSnapshotForm');
    document.getElementById('btnSnapCreate')?.addEventListener('click', function() {
        if (createSnapshotForm) {
            showConfirmation('Create snapshot?', function() {
                var inp = document.createElement('input'); inp.type='hidden'; inp.name='create_snap'; inp.value='1';
                createSnapshotForm.appendChild(inp);
                showSpinner(); createSnapshotForm.submit();
            });
        }
    });
    document.getElementById('btnSnapDelete')?.addEventListener('click', function() {
        if (!snapForm.snap_select.value) {
            showConfirmation('Please select a snapshot to delete.');
            return;
        }
        showConfirmation('Delete the selected snapshot? This is irreversible.', function() {
            var inp = document.createElement('input'); inp.type='hidden'; inp.name='delete_snap'; inp.value='1';
            snapForm.appendChild(inp);
            showSpinner(); snapForm.submit();
        });
    });
    document.getElementById('btnSnapRollback')?.addEventListener('click', function() {
        if (!snapForm.snap_select.value) {
            showConfirmation('Please select a snapshot to rollback.');
            return;
        }
        showConfirmation('Rollback to the selected snapshot? This will overwrite the origin.', function() {
            var inp = document.createElement('input'); inp.type='hidden'; inp.name='rollback_snap'; inp.value='1';
            snapForm.appendChild(inp);
            showSpinner(); snapForm.submit();
        });
    });
    var openRemoveVg = document.getElementById('btnOpenRemoveVg');
    var thinPoolForm = document.getElementById('createThinPoolForm');
    if (thinPoolForm) {
        thinPoolForm.addEventListener('submit', function(e) {
            e.preventDefault();
            showConfirmation('Create the thin pool? Existing data may be overwritten.', function() {
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'create_thinpool';
                inp.value = '1';
                thinPoolForm.appendChild(inp);
                showSpinner();
                thinPoolForm.submit();
            });
        });
    }
    if (openRemoveVg) {
        openRemoveVg.addEventListener('click', function() {
            // collect checked groups
            var selected = [];
            document.querySelectorAll('#vgModal .vg-checkbox:checked').forEach(function(cb) {
                if (cb.value) selected.push(cb.value);
            });
            if (selected.length === 0) {
                showConfirmation('Please select at least one volume group to remove.');
                return;
            }
            var msg = 'The following volume groups will be removed and all data lost:<br>' + selected.join('<br>');
            showConfirmation(msg, function() {
                var form = document.createElement('form');
                form.method = 'post';
                form.style.display = 'none';
                selected.forEach(function(v) {
                    var inp = document.createElement('input');
                    inp.type = 'hidden'; inp.name = 'vg_select[]'; inp.value = v;
                    form.appendChild(inp);
                });
                var h = document.createElement('input');
                h.type = 'hidden'; h.name = 'remove_vg'; h.value = '1';
                form.appendChild(h);
                document.body.appendChild(form);
                form.submit();
            });
        });
    }
    // extend selected vg button in modal
    var openExtendSelected = document.getElementById('btnExtendSelectedVgs');
    if (openExtendSelected) {
        openExtendSelected.addEventListener('click', function() {
            var selected = [];
            document.querySelectorAll('#vgModal .vg-checkbox:checked').forEach(function(cb) {
                if (cb.value) selected.push(cb.value);
            });
            if (selected.length === 0) {
                showConfirmation('Please select at least one volume group to extend.');
                return;
            }
            // read unassignedPvs from data attribute on extendVgModal
            var extendModal = document.getElementById('extendVgModal');
            var unassignedPvs = [];
            if (extendModal && extendModal.dataset.unassignedPvs) {
                try {
                    unassignedPvs = JSON.parse(extendModal.dataset.unassignedPvs);
                } catch (e) {
                    console.error('failed to parse unassignedPvs', e);
                }
            }
            var modal = document.getElementById('extendSelectedVgModal');
            var bodyForm = modal.querySelector('#extendSelectedForm');
            bodyForm.innerHTML = '';
            selected.forEach(function(vg) {
                var section = document.createElement('div');
                section.className = 'mb-3';
                var label = document.createElement('label');
                label.className = 'form-label';
                label.textContent = vg;
                section.appendChild(label);
                if (!unassignedPvs || unassignedPvs.length === 0) {
                    var em = document.createElement('div'); em.innerHTML = '<em>No unused physical volumes available.</em>';
                    section.appendChild(em);
                } else {
                    unassignedPvs.forEach(function(pv) {
                        var checkdiv = document.createElement('div');
                        checkdiv.className = 'form-check';
                        var inp = document.createElement('input');
                        inp.className = 'form-check-input';
                        inp.type = 'checkbox';
                        inp.name = 'pvs[' + vg + '][]';
                        inp.value = pv;
                        inp.id = 'ext_' + vg + '_' + pv.replace(/[^a-zA-Z0-9]/g,'_');
                        var lab = document.createElement('label');
                        lab.className = 'form-check-label';
                        lab.htmlFor = inp.id;
                        lab.textContent = pv;
                        checkdiv.appendChild(inp);
                        checkdiv.appendChild(lab);
                        section.appendChild(checkdiv);
                    });
                }
                var hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = 'vg_name[]';
                hidden.value = vg;
                section.appendChild(hidden);
                bodyForm.appendChild(section);
            });
            var vgModal = document.getElementById('vgModal');
            var inst = bootstrap.Modal.getInstance(vgModal);
            if (inst && vgModal.classList.contains('show')) inst.hide();
            new bootstrap.Modal(modal).show();
        });
    }
    var vgModalElt = document.getElementById('vgModal');
    if (vgModalElt) {
        vgModalElt.addEventListener('shown.bs.modal', function(){
            var selAll = document.getElementById('selectAllVgs');
            if (selAll) {
                selAll.checked = false;
                selAll.addEventListener('change', function() {
                    var chk = this.checked;
                    document.querySelectorAll('#vgModal .vg-checkbox').forEach(function(cb){ cb.checked = chk; });
                });
            }
            // when any individual box is unchecked, clear master
            document.querySelectorAll('#vgModal .vg-checkbox').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    if (!cb.checked && selAll) selAll.checked = false;
                });
            });
        });
    }

    // intercept the Add-to-VG button rather than the form itself; this
    // lets us construct a fresh POST payload after the confirmation dialog
    // (avoiding issues caused by hiding the existing form).
    var extendBtn = document.querySelector('#extendVgModal button[name="extend_vg"]');
    if (extendBtn) {
        extendBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Add selected physical volumes to the volume group? This will modify the VG.', function() {
                var vgName = document.querySelector('#extendVgForm input[name="vg_name"]').value;
                var selected = Array.from(document.querySelectorAll('#extendVgForm input[name="pvs[]"]:checked')).map(function(ch){return ch.value;});
                console.log('extending VG', vgName, 'with pvs', selected);
                var topForm = document.createElement('form');
                topForm.method = 'post';
                topForm.style.display = 'none';
                // vg name
                var inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'vg_name'; inp.value = vgName;
                topForm.appendChild(inp);
                // selected PVs
                selected.forEach(function(val) {
                    var h = document.createElement('input');
                    h.type = 'hidden'; h.name = 'pvs[]'; h.value = val;
                    topForm.appendChild(h);
                });
                var h2 = document.createElement('input');
                h2.type = 'hidden'; h2.name = 'extend_vg'; h2.value = '1';
                topForm.appendChild(h2);
                document.body.appendChild(topForm);
                topForm.submit();
            });
        });
    }
    // multi-extend submission interceptor
    var extendMultiBtn = document.querySelector('#extendSelectedVgModal button[name="extend_vg_multi"]');
    if (extendMultiBtn) {
        extendMultiBtn.addEventListener('click', function(e) {
            e.preventDefault();
            // ensure at least one PV is checked across all groups
            var anyChecked = document.querySelectorAll('#extendSelectedForm input[type="checkbox"]:checked').length > 0;
            if (!anyChecked) {
                showConfirmation('Please select at least one physical volume to add before submitting.');
                return;
            }
            showConfirmation('Add selected physical volumes to the chosen volume groups? This will modify the VGs.', function() {
                var topForm = document.createElement('form');
                topForm.method = 'post';
                topForm.style.display = 'none';
                // copy vg_name[] hidden inputs
                document.querySelectorAll('#extendSelectedForm input[name="vg_name[]"]').forEach(function(inp) {
                    var h = document.createElement('input');
                    h.type = 'hidden'; h.name = 'vg_name[]'; h.value = inp.value;
                    topForm.appendChild(h);
                });
                // copy PV checkboxes (names like pvs[vg][])
                document.querySelectorAll('#extendSelectedForm input[type="checkbox"]:checked').forEach(function(inp) {
                    var h = document.createElement('input');
                    h.type = 'hidden';
                    h.name = inp.name;
                    h.value = inp.value;
                    topForm.appendChild(h);
                });
                var h2 = document.createElement('input');
                h2.type = 'hidden'; h2.name = 'extend_vg_multi'; h2.value = '1';
                topForm.appendChild(h2);
                document.body.appendChild(topForm);
                topForm.submit();
            });
        });
    }

    // when certain modals close, return to their parent (VG or LV) modal
    ['createVgModal','extendVgModal','extendSelectedVgModal'].forEach(function(id) {
        var m = document.getElementById(id);
        if (m) {
            m.addEventListener('hidden.bs.modal', function() {
                if (window._confirmActive) {
                    return;
                }
                var vg = document.getElementById('vgModal');
                var bs = bootstrap.Modal.getInstance(vg) || new bootstrap.Modal(vg);
                bs.show();
            });
        }
    });
    ['createLvModal','formatLvModal','removeLvModal'].forEach(function(id) {
        var m = document.getElementById(id);
        if (m) {
            m.addEventListener('hidden.bs.modal', function() {
                if (window._confirmActive) {
                    return;
                }
                var lv = document.getElementById('lvModal');
                var bs = bootstrap.Modal.getInstance(lv) || new bootstrap.Modal(lv);
                bs.show();
            });
        }
    });
});

// helper used by both the modal submit interceptor and the
// confirmation callbacks; posts the form by AJAX and refreshes the info
// modal contents with whatever HTML the server returns.
// we also ensure the disk field is sent and provide a warning if the
// response is empty (a missing disk value is the usual culprit).
function submitDiskFormAjax(form) {
    // whenever we start an AJAX disk operation show the spinner overlay so
    // users see that something is happening; the fetch below runs asynchronously
    // so the overlay might disappear only when the response arrives and we
    // refresh the modal contents.  hideSpinner() will be called once we get a
    // response or an error so the screen isn’t blocked indefinitely.
    showSpinner();
    console.log('submitDiskFormAjax invoked, form=', form, 'lastSubmitName=', form._lastSubmitName);
    var data = new FormData(form);
    // ensure disk field is always sent (some browsers drop empty hidden inputs)
    var diskInput = form.querySelector('input[name="disk"]');
    if (diskInput && diskInput.value) {
        data.set('disk', diskInput.value);
    }
    if (form._lastSubmitName) {
        data.append(form._lastSubmitName, form._lastSubmitValue);
    }
    data.append('ajax', '1');
    fetchAuth('views/disks.php', { method: 'POST', body: data })
        .then(function(resp) { return resp.text(); })
        .then(function(newHtml) {
            // hide spinner as soon as we begin processing response
            hideSpinner();
            if (newHtml.trim() === '') {
                // nothing returned – most likely the disk value was missing
                showResult('Error: no response from server (disk may be unset)');
                return;
            }
            // hide any submodal that might still be open
            ['createPartModal','deletePartModal','formatPartModal'].forEach(function(id) {
                var m = document.getElementById(id);
                var inst = bootstrap.Modal.getInstance(m);
                if (inst && m.classList.contains('show')) inst.hide();
            });

            // parse the returned HTML so we can inspect/strip special markers
            var temp = document.createElement('div');
            temp.innerHTML = newHtml;
            var genericMsg = null;
            // capture any bootstrap info alert text
            var alertElt = temp.querySelector('.alert.alert-info');
            if (alertElt) {
                // use innerHTML so any <pre> or other markup is preserved when
                // we later inject the message via showResult().  Previously we
                // used textContent which stripped newlines and collapsed the
                // SMART output into a single line.
                genericMsg = alertElt.innerHTML.trim();
                alertElt.remove();
            }
            var res = temp.querySelector('#formatResult');
            var fmtOk, fmtMsg;
            if (res) {
                fmtOk = res.dataset.ok === '1';
                fmtMsg = res.dataset.msg || (fmtOk ? 'Format completed.' : 'Format failed.');
                res.remove();
            }
            newHtml = temp.innerHTML;

            // update the preview first
            showInfo(newHtml);

            // then, if we had any result message, show it in the result modal
            if (typeof fmtOk !== 'undefined' || genericMsg) {
                var infoModal = document.getElementById('infoModal');
                var doShow = function() { showResult(genericMsg || fmtMsg); };
                if (infoModal && infoModal.classList.contains('show')) {
                    doShow();
                } else if (infoModal) {
                    var handler = function() {
                        infoModal.removeEventListener('shown.bs.modal', handler);
                        doShow();
                    };
                    infoModal.addEventListener('shown.bs.modal', handler);
                } else {
                    doShow();
                }
            }
        })
        .catch(function(err) {
            console.error('modal form ajax error', err);
            hideSpinner();
        });
}

function openAddRaidModal(raidDev, isSpare) {
    var modal = document.getElementById('addRaidModal');
    if (!modal) return;
    modal.dataset.spare = isSpare ? '1' : '0';
    // hide any underlying info/details modal so our dialog is on top
    var infoModal = document.getElementById('infoModal');
    if (infoModal) {
        var inst = bootstrap.Modal.getInstance(infoModal);
        if (inst && infoModal.classList.contains('show')) inst.hide();
    }
    var select = modal.querySelector('select[name="new_disk"]');
    select.innerHTML = '<option value="">(loading…)</option>';
    fetchAuth('views/raid.php?json_unused=1')
        .then(function(r){ return r.json(); })
        .then(function(list){
            select.innerHTML = '';
            if (!Array.isArray(list) || list.length === 0) {
                select.innerHTML = '<option value="">(no disks)</option>';
            } else {
                list.forEach(function(d){
                    var o = document.createElement('option');
                    o.value = d; o.textContent = d;
                    select.appendChild(o);
                });
            }
        })
        .catch(function(err){
            console.error('list unused disks error', err);
            select.innerHTML = '<option value="">error</option>';
        });
    modal.querySelector('input[name="raid_select"]').value = raidDev;
    new bootstrap.Modal(modal).show();
}

function openFailRaidModal(raidDev) {
    var modal = document.getElementById('failRaidModal');
    if (!modal) return;
    var infoModal = document.getElementById('infoModal');
    if (infoModal) {
        var inst = bootstrap.Modal.getInstance(infoModal);
        if (inst && infoModal.classList.contains('show')) inst.hide();
    }
    var select = modal.querySelector('select[name="member"]');
    select.innerHTML = '<option value="">(loading…)</option>';
    fetchAuth('views/raid.php?json_members=1&raid=' + encodeURIComponent(raidDev))
        .then(function(r){ return r.json(); })
        .then(function(list){
            select.innerHTML = '';
            if (!Array.isArray(list) || list.length === 0) {
                select.innerHTML = '<option value="">(no members)</option>';
            } else {
                list.forEach(function(m){
                    var o = document.createElement('option');
                    o.value = m; o.textContent = m;
                    select.appendChild(o);
                });
            }
        })
        .catch(function(err){
            console.error('list members error', err);
            select.innerHTML = '<option value="">error</option>';
        });
    modal.querySelector('input[name="raid_select"]').value = raidDev;
    new bootstrap.Modal(modal).show();
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
                // include the button name/value so the server sees wipe_disk
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = wipeBtn.name;
                inp.value = wipeBtn.value || '';
                wipeBtn.form.appendChild(inp);
                submitDiskFormAjax(wipeBtn.form);
            });
        });
    }
    // format button handler similar to wipe
    var formatBtn = root.querySelector('#btnOpenFormat');
    if (formatBtn) {
        // no direct action here; formatting happens in submodal form
    }
    var wipeBtn = root.querySelector('#btnWipe');
    if (wipeBtn) {
        wipeBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Wipe the selected disk (GPT table and superblocks)? This cannot be undone.', function() {
                // include the button name/value so the server sees wipe_disk
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = wipeBtn.name;
                inp.value = wipeBtn.value || '';
                wipeBtn.form.appendChild(inp);
                submitDiskFormAjax(wipeBtn.form);
            });
        });
    }

    // RAID-specific action buttons inside a raid info modal
    var raidActions = root.querySelectorAll('#btnPartitionRaid, #btnFormatRaid, #btnAddRaid, #btnAddSpareRaid, #btnFailRaid, #btnRebuildRaid, #btnRemoveRaid');
    raidActions.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            var action = btn.id.replace('btn','').toLowerCase();
            var raidDev = btn.dataset.raid;
            console.log('raid action click', action, raidDev);
            if (action === 'partitionraid') {
                // immediately switch to disk view without extra confirmation
                fetchAuth('views/disks.php?ajax=1&raid=1&disk=' + encodeURIComponent(raidDev))
                    .then(function(resp){ return resp.text(); })
                    .then(function(html){ showInfo(html); })
                    .catch(function(err){ console.error('raid->disk AJAX error', err); });
            } else if (action === 'addraid' || action === 'addspareraid') {
                openAddRaidModal(raidDev, action === 'addspareraid');
            } else if (action === 'failraid') {
                openFailRaidModal(raidDev);
            } else {
                var msgText;
                switch(action) {
                    case 'formatraid':
                        msgText = 'Format the RAID device ' + raidDev + '? All data will be lost.';
                        break;
                    case 'rebuildraid':
                        msgText = 'Initiate rebuild for ' + raidDev + '?';
                        break;
                    case 'removeraid':
                        msgText = 'Stop and remove the RAID array ' + raidDev + '? Data will be lost.';
                        break;
                    default:
                        msgText = 'Proceed with action?';
                }
                showConfirmation(msgText, function() {
                    var f = document.createElement('form');
                    f.method = 'post';
                    // ensure we POST back to the current page (including query args)
                    f.action = window.location.pathname + window.location.search;
                    f.style.display = 'none';
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'raid_select';
                    inp.value = raidDev;
                    f.appendChild(inp);
                    var act = document.createElement('input');
                    act.type = 'hidden';
                    // PHP expects underscore names for some actions
                    var phpName = action;
                    if (action === 'removeraid') phpName = 'remove_raid';
                    if (action === 'formatraid') phpName = 'format_raid';
                    if (action === 'rebuildraid') phpName = 'rebuild_raid';
                    act.name = phpName;
                    act.value = '1';
                    f.appendChild(act);
                    document.body.appendChild(f);
                    showSpinner();
                    f.submit();
                });
            }
        });
    });
}

function showInfo(html) {
    var modal = document.getElementById('infoModal');
    if (!modal) return;
    var body = modal.querySelector('.modal-body');
    body.innerHTML = html;
    // attach handlers for any form buttons inside (delete/wipe requires confirm)
    attachDiskHandlers(body);

    // wire up the open‑modal buttons as well
    var btn = body.querySelector('#btnOpenCreate');
    if (btn) btn.addEventListener('click', function() { openSubmodal('createPartModal', btn.dataset.disk); });
    var btn2 = body.querySelector('#btnOpenDelete');
    if (btn2) btn2.addEventListener('click', function() { openSubmodal('deletePartModal', btn2.dataset.disk); });
    var btnFmt = body.querySelector('#btnOpenFormat');
    if (btnFmt) btnFmt.addEventListener('click', function() { openSubmodal('formatPartModal', btnFmt.dataset.disk); });
    // raid-member links for partitioning underlying disks
    body.querySelectorAll('a.raid-member').forEach(function(link) {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            var dev = link.dataset.dev || '';
            if (dev) {
                fetchAuth('views/disks.php?ajax=1&raid=1&disk=' + encodeURIComponent(dev))
                    .then(function(resp){ return resp.text(); })
                    .then(function(html){ showInfo(html); })
                    .catch(function(err){ console.error('member AJAX error', err); });
            }
        });
    });

    // move any action buttons/forms into the modal footer so they're aligned
    // with the Close button
    var actions = body.querySelector('.action-buttons');
    if (actions) {
        var footer = modal.querySelector('.modal-footer');
        // remove existing non-close buttons in footer
        footer.querySelectorAll('button:not([data-bs-dismiss])').forEach(function(b) { b.remove(); });
        // insert actions *before* the close button so close stays rightmost
        var closeBtn = footer.querySelector('[data-bs-dismiss]');
        if (closeBtn) {
            footer.insertBefore(actions, closeBtn);
        } else {
            footer.appendChild(actions);
        }
    }

    // convert any forms in the modal (body or footer) into AJAX submissions so
    // the surrounding page isn’t replaced with card HTML; after a POST we
    // simply refresh the modal contents with the updated response.
    modal.querySelectorAll('form').forEach(function(form) {
        // remember which submit button was clicked so its name/value can be
        // included (FormData doesn’t automatically include the button unless
        // it’s passed to the constructor).  this fixes the “create partition”
        // action not being seen on AJAX submits.
        // record the name/value of whichever submit button was clicked
        // (some buttons omit the type attribute; browsers default them to
        // "submit" so check the property rather than the attribute selector).
        form.querySelectorAll('button').forEach(function(btn) {
            try {
                if (btn.type && btn.type.toLowerCase() === 'submit') {
                    btn.addEventListener('click', function() {
                        form._lastSubmitName = btn.name;
                        form._lastSubmitValue = btn.value || '';
                    });
                }
            } catch (e) {
                // in case btn.type is inaccessible for some reason just skip
            }
        });

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            submitDiskFormAjax(form);
        });
    });

    // reuse existing bootstrap.Modal instance to avoid stacking backdrops when
    // the modal is already visible.
    var bsModal = bootstrap.Modal.getInstance(modal) || new bootstrap.Modal(modal);
    if (!modal.classList.contains('show')) {
        bsModal.show();
    }
}

// open a secondary partition modal; hide infoModal first so backdrop stays
// sensible and we can restore the preview when the submodal closes.
function openSubmodal(subId, disk) {
    var info = document.getElementById('infoModal');
    var infoInst = bootstrap.Modal.getInstance(info);
    var doShow = function() {
        var sub = document.getElementById(subId);
        if (!sub) return;
        // populate disk field
        var hid = sub.querySelector('input[name=disk]');
        if (hid) hid.value = disk;
        // if this is an md device being edited from RAID view, make sure the
        // hidden raid flag is present so subsequent refreshes don't disable
        // the buttons.
        if (disk.startsWith('/dev/md')) {
            var raidInp = sub.querySelector('input[name=raid]');
            if (!raidInp) {
                raidInp = document.createElement('input');
                raidInp.type = 'hidden';
                raidInp.name = 'raid';
                raidInp.value = '1';
                // put inside first form if present
                var form = sub.querySelector('form');
                if (form) form.appendChild(raidInp);
                else sub.appendChild(raidInp);
            } else {
                raidInp.value = '1';
            }
        }
        // clear other inputs (size/partnum) to avoid leftover values
        sub.querySelectorAll('input[name="size"], select[name="part_num"]').forEach(function(i){ i.value = ''; });
        // warn when opening the partition creation dialog on an md device
        if (subId === 'createPartModal' && disk.startsWith('/dev/md')) {
            if (!sub.querySelector('.raid-note')) {
                var note = document.createElement('div');
                note.className = 'alert alert-warning raid-note';
                note.textContent = 'RAID devices often report "unrecognised disk label" and may not display partitions here; the UI will show a success/error message and may fall back to sgdisk if parted fails.';
                var body = sub.querySelector('.modal-body');
                if (body) body.insertBefore(note, body.firstChild);
            }
        }
        // if this is delete or format modal, populate the partition dropdown
        if (subId === 'deletePartModal' || subId === 'formatPartModal') {
            var sel = sub.querySelector('select[name="part_num"]');
            if (sel) {
                sel.innerHTML = '<option value="">Loading…</option>';
                fetchAuth('views/disks.php?list_parts=1&disk=' + encodeURIComponent(disk))
                    .then(function(r){ return r.json(); })
                    .then(function(list){
                        sel.innerHTML = '';
                        if (!Array.isArray(list) || list.length === 0) {
                            sel.innerHTML = '<option value="">(no partitions)</option>';
                        } else {
                            list.forEach(function(p){
                                var o = document.createElement('option');
                                o.value = p.num;
                                o.textContent = p.num + ': ' + p.label;
                                sel.appendChild(o);
                            });
                        }
                    })
                    .catch(function(err){
                        console.error('partition list error', err);
                        sel.innerHTML = '<option value="">error</option>';
                    });
            }
        }
        // if format modal, default filesystem to ext4 if available
        if (subId === 'formatPartModal') {
            var fsel = sub.querySelector('select[name="fstype"]');
            if (fsel) {
                // if the select lost its options (possible after a reload) use the
                // cached list we gathered on page load
                if (fsel.options.length <= 1 && cachedFsTypes.length) {
                    fsel.innerHTML = '';
                    cachedFsTypes.forEach(function(fs) {
                        var o = document.createElement('option');
                        o.value = fs; o.textContent = fs;
                        fsel.appendChild(o);
                    });
                }
                if ([].slice.call(fsel.options).some(o=>o.value==='ext4')) {
                    fsel.value = 'ext4';
                }
            }
        }
        // attach handlers inside the submodal as well (delete confirmation)
        attachDiskHandlers(sub);
        // intercept forms for AJAX submission just like showInfo does
        sub.querySelectorAll('form').forEach(function(form) {
            // avoid binding twice if modal opened multiple times
            if (form._ajaxBound) return;
            form._ajaxBound = true;
            form.querySelectorAll('button[type=submit]').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    form._lastSubmitName = btn.name;
                    form._lastSubmitValue = btn.value || '';
                });
            });
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                // hide any open submodal; the confirmation will appear above all
                ['formatPartModal','createPartModal','deletePartModal'].forEach(function(id){
                    var m = document.getElementById(id);
                    if (m) {
                        var inst = bootstrap.Modal.getInstance(m);
                        if (inst && m.classList.contains('show')) inst.hide();
                    }
                });

                // figure out which button triggered the submit (modern browsers)
                var btn = e.submitter || null;
                var action = btn && btn.name ? btn.name : '';
                // store for backwards compatibility
                form._lastSubmitName = action;

                var confirmMsg = null;
                switch(action) {
                    case 'format_part':
                        confirmMsg = 'Format the selected partition? This will destroy all data on it.';
                        break;
                    case 'create_part_size':
                    case 'create_part':
                        confirmMsg = 'Create this partition? This may overwrite existing data.';
                        break;
                    case 'delete_part':
                        confirmMsg = 'Delete the selected partition? This is destructive.';
                        break;
                }

                if (confirmMsg) {
                    showConfirmation(confirmMsg, function() {
                        submitDiskFormAjax(form);
                    });
                } else {
                    submitDiskFormAjax(form);
                }
            });
        });
        var bs = new bootstrap.Modal(sub);
        bs.show();
    };
    if (infoInst && info.classList.contains('show')) {
        info.addEventListener('hidden.bs.modal', function handler() {
            info.removeEventListener('hidden.bs.modal', handler);
            doShow();
        });
        infoInst.hide();
    } else {
        doShow();
    }
}

function showConfirmation(text, onOk, onCancel) {
    // confirmation dialog called
    // signal that a confirmation is in-flight; handlers should avoid
    // re-opening any parent modals.
    window._confirmActive = true;
    // hide any visible modals before showing confirmation so its backdrop
    // and z-index will sit on top.  this covers infoModal, raid, VG, extend,
    // or any other dialogs that might be open.
    var toHide = [];
    document.querySelectorAll('.modal.show').forEach(function(m) {
        if (m.id === 'confirmModal') return; // skip self
        toHide.push(m);
    });
    var remaining = toHide.length;
    var done = function() {
        remaining--;
        if (remaining <= 0) {
            actuallyShowConfirm();
        }
    };
    if (remaining === 0) {
        actuallyShowConfirm();
    } else {
        toHide.forEach(function(m) {
            m.addEventListener('hidden.bs.modal', function handler() {
                m.removeEventListener('hidden.bs.modal', handler);
                done();
            });
            var inst = bootstrap.Modal.getInstance(m);
            if (inst) inst.hide();
        });
    }

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
            // confirmation OK clicked
            // execute callback immediately, before we hide the dialog, to
            // avoid situations where the form is removed from the DOM during
            // the hide animation and the browser refuses to submit it.
            if (onOk) {
                try {
                    onOk();
                } catch (e) {
                    console.error('error in onOk callback', e);
                }
            }
            var bs = bootstrap.Modal.getInstance(modal);
            // now hide the modal (animation may still run but form has already
            // been submitted)
            if (bs) bs.hide();
        };
        // always show cancel button; if no handler is supplied it simply
        // closes the dialog.  previously the button was hidden when the
        // caller passed only two arguments (message + onOk), which meant
        // confirmations on the mounts page had no obvious way to abort.
        cancelBtn.style.display = '';
        cancelBtn.onclick = function() {
            if (onCancel) {
                try { onCancel(); } catch (e) { console.error('error in onCancel callback', e); }
            }
            var bs = bootstrap.Modal.getInstance(modal);
            if (bs) bs.hide();
        };
        var bsModal = new bootstrap.Modal(modal);
        // show after a tiny delay so any existing backdrop from a just-closed
        // modal has been removed; this prevents the new dialog from ending up
        // visually beneath the old backdrop.
        setTimeout(function() {
            bsModal.show();
            // ensure confirm modal z-index is higher than any remaining show
            var highest = 1055;
            document.querySelectorAll('.modal.show').forEach(function(m) {
                if (m === modal) return;
                var z = parseInt(window.getComputedStyle(m).zIndex) || 0;
                if (z >= highest) highest = z + 10;
            });
            modal.style.zIndex = highest;
            // bump backdrop too
            var back = document.querySelector('.modal-backdrop:last-of-type');
            if (back) back.style.zIndex = highest - 5;
        }, 10);
        // clear flag when confirm is dismissed so parent modals can reopen
        modal.addEventListener('hidden.bs.modal', function() {
            window._confirmActive = false;
        }, {once: true});
    }
}

// helper for simple notification modal (does not close other modals)
function showResult(text) {
    var modal = document.getElementById('resultModal');
    if (!modal) return;
    var body = modal.querySelector('.modal-body');
    body.innerHTML = text;
    // compute a z-index higher than any currently shown modal
    var highest = 1055;
    document.querySelectorAll('.modal.show').forEach(function(m) {
        var z = parseInt(window.getComputedStyle(m).zIndex) || 0;
        if (z >= highest) highest = z + 10;
    });
    modal.style.zIndex = highest;
    var bs = bootstrap.Modal.getInstance(modal) || new bootstrap.Modal(modal);
    bs.show();
    // also bump the backdrop after it appears
    modal.addEventListener('shown.bs.modal', function() {
        var back = document.querySelector('.modal-backdrop:last-of-type');
        if (back) back.style.zIndex = highest - 5;
    }, {once: true});
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


    // support any remove button generated per row or the old single button
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
                // make sure the form action is correct
                btn.form.action = window.location.pathname + window.location.search;
                btn.form.submit();
            });
        });
    });
    }

    // Create RAID button uses data-bs attributes; no additional JS needed here


    // mount/unmount confirmation on mounts.php
    var mountBtn = document.getElementById('btnMount');
    var mountForm = document.getElementById('mountForm');
    if (mountForm) {
        // ensure submit wrapper still in place in case bootstrap relocates form
        try {
            var origSubmit = mountForm.submit;
            mountForm.submit = function() {
                origSubmit.call(mountForm);
            };
        } catch(e) {
            // ignore
        }
    }
    if (mountBtn) {
        mountBtn.addEventListener('click', function(e) {
                e.preventDefault();
            // gather the device and mount-point entered in the form so the
            // confirmation message can show the real values instead of the
            // generic placeholder used previously.
            var form = mountBtn.form;
            var dev = '';
            var sub = '';
            if (form) {
                if (form.device_select_mount) {
                    dev = form.device_select_mount.value || '';
                }
                if (form.mount_point) {
                    sub = form.mount_point.value || '';
                }
            }
            var msg = 'Mount the selected logical volume?';
            if (dev) {
                msg = 'Mount ' + dev + '?';
            }
            msg += '\nThis will create or use /export/' + (sub ? sub : '<em>subdir</em>') + '.\nNewly created directories are automatically chown\'ed to nobody:nogroup so they can be exported via NFS.';
            showConfirmation(msg, function() {
                // ensure mount button name included and submit
                if (!mountBtn.form.querySelector('input[name="mount_lv"]')) {
                    var hid = document.createElement('input');
                    hid.type = 'hidden';
                    hid.name = 'mount_lv';
                    hid.value = mountBtn.value || '1';
                    mountBtn.form.appendChild(hid);
                }
                try {
                    mountBtn.form.submit();
                } catch (e) {
                    console.error('exception when calling submit', e);
                }
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

    // prepare create-export modal on show, including client table logic
    function addClientRow(client, opts) {
        var tbody = document.querySelector('#clientTable tbody');
        if (!tbody) return;
        var tr = document.createElement('tr');
        tr.innerHTML = '<td class="client-cell">'+(client||'')+'</td>' +
                       '<td class="opts-cell">'+(opts||'')+'</td>' +
                       '<td>' +
                         '<button type="button" class="btn btn-sm btn-secondary btn-edit-client">&#9998;</button> ' +
                         '<button type="button" class="btn btn-sm btn-danger btn-del-client">&times;</button>' +
                       '</td>';
        tbody.appendChild(tr);
        tr.querySelector('.btn-del-client').addEventListener('click', function() {
            tr.remove();
        });
        tr.querySelector('.btn-edit-client').addEventListener('click', function() {
            showClientModal(function(c,o){
                tr.querySelector('.client-cell').textContent = c;
                tr.querySelector('.opts-cell').textContent = o;
            }, client, opts);
        });
    }
    // helper to add a name/value option row in samba share forms
    function addOptionRow(modal, key, val) {
        if (!modal) return;
        var tbody = modal.querySelector('.shareOptionTable tbody');
        if (!tbody) return;
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><input list="shareOptionNames" type="text" name="opt_name[]" class="form-control form-control-sm" value="'+(key||'')+'"></td>' +
                       '<td><input type="text" name="opt_val[]" class="form-control form-control-sm" value="'+(val||'')+'"></td>' +
                       '<td><button type="button" class="btn btn-sm btn-danger btn-del-opt">&times;</button></td>';
        tbody.appendChild(tr);
        tr.querySelector('.btn-del-opt').addEventListener('click', function() {
            tr.remove();
        });
    }

    var createExportModal = document.getElementById('createExportModal');
    if (createExportModal) {
        createExportModal.addEventListener('show.bs.modal', function() {
                var form = document.getElementById('createExportForm');
            if (!form) return;
            form.reset();
            // clear table and add initial blank row
            var tbody = document.querySelector('#clientTable tbody');
            if (tbody) tbody.innerHTML = '';
            addClientRow();
        });
        // bind add button once
        var addBtn = document.getElementById('addClientBtn');
        if (addBtn) {
            addBtn.onclick = function() {
                showClientModal(function(c,o){ addClientRow(c,o); });
            };
        }
        // client-entry modal helper with structured option controls
        function showClientModal(onSave, initialClient, initialOpts) {
            var clientModal = document.getElementById('clientEntryModal');
            if (!clientModal) return;
            var addr = document.getElementById('clientAddr');
            addr.value = initialClient || '';
            // reset selects/checkbox/numeric inputs
            var rwsel = document.getElementById('opt_rw_ro');
            if (rwsel) rwsel.value = '';
            ['opt_squash','opt_sync','opt_subtree','opt_wdelay'].forEach(function(id){
                var s = document.getElementById(id);
                if (s) s.value = '';
            });
            ['noaccess','crossmnt','nohide'].forEach(function(o){
                var chk = document.getElementById('opt_' + o);
                if (chk) chk.checked = false;
            });
            ['anonuid','anongid','fsid'].forEach(function(o){
                var inp = document.getElementById('opt_' + o);
                if (inp) inp.value = '';
            });
            // populate existing options or use defaults if none provided
            var optsToApply = initialOpts || '';
            if (!optsToApply) {
                optsToApply = 'rw,root_squash,no_subtree_check';
            }
            var parts = optsToApply.split(',');
            parts.forEach(function(p) {
                if (!p) return;
                if (p === 'rw' || p === 'ro') {
                    if (rwsel) rwsel.value = p;
                } else if (['root_squash','no_root_squash','all_squash'].includes(p)) {
                    var sel = document.getElementById('opt_squash');
                    if (sel) sel.value = p;
                } else if (['sync','async'].includes(p)) {
                    var sel = document.getElementById('opt_sync');
                    if (sel) sel.value = p;
                } else if (['subtree_check','no_subtree_check'].includes(p)) {
                    var sel = document.getElementById('opt_subtree');
                    if (sel) sel.value = p;
                } else if (['wdelay','no_wdelay'].includes(p)) {
                    var sel = document.getElementById('opt_wdelay');
                    if (sel) sel.value = p;
                } else if (['noaccess','crossmnt','nohide'].includes(p)) {
                    var chk = document.getElementById('opt_' + p);
                    if (chk) chk.checked = true;
                } else if (p.indexOf('=') !== -1) {
                    var kv = p.split('=');
                    var el = document.getElementById('opt_' + kv[0]);
                    if (el) el.value = kv[1];
                }
            });
            var form = document.getElementById('clientForm');
            var handler = function(e) {
                e.preventDefault();
                var c = addr.value.trim();
                var optsArr = [];
                var rwsel = document.getElementById('opt_rw_ro');
                if (rwsel && rwsel.value) optsArr.push(rwsel.value);
                ['opt_squash','opt_sync','opt_subtree','opt_wdelay'].forEach(function(id){
                    var sel = document.getElementById(id);
                    if (sel && sel.value) optsArr.push(sel.value);
                });
                ['noaccess','crossmnt','nohide'].forEach(function(o){
                    var chk = document.getElementById('opt_' + o);
                    if (chk && chk.checked) optsArr.push(o);
                });
                ['anonuid','anongid','fsid'].forEach(function(o){
                    var inp = document.getElementById('opt_' + o);
                    if (inp && inp.value.trim() !== '') {
                        optsArr.push(o + '=' + inp.value.trim());
                    }
                });
                var o = optsArr.join(',');
                if (c) {
                    onSave(c, o);
                }
                var bs = bootstrap.Modal.getInstance(clientModal);
                if (bs) bs.hide();
            };
            form.addEventListener('submit', handler, {once:true});
            new bootstrap.Modal(clientModal).show();
        }
        // build export_line on submit (create form)
        var createForm = document.getElementById('createExportForm');
        if (createForm) {
            createForm.addEventListener('submit', function(e) {
                var dir = createForm.export_dir.value.trim();
                if (!dir) {
                    e.preventDefault();
                    showConfirmation('Please select a directory to export.');
                    return;
                }
                var clients = [];
                document.querySelectorAll('#clientTable tbody tr').forEach(function(row) {
                    var c = row.querySelector('.client-cell').textContent.trim();
                    var o = row.querySelector('.opts-cell').textContent.trim();
                    if (c) {
                        if (o) clients.push(c + '(' + o + ')');
                        else clients.push(c);
                    }
                });
                if (clients.length === 0) {
                    e.preventDefault();
                    showConfirmation('Please add at least one client entry.');
                    return;
                }
                createForm.export_line.value = dir + ' ' + clients.join(' ');
                // comment is handled server-side separately
            });
        }
    }

    // helper for edit modal row addition
    function addEditClientRow(client, opts) {
        var tbody = document.querySelector('#editClientTable tbody');
        if (!tbody) return;
        var tr = document.createElement('tr');
        tr.innerHTML = '<td class="client-cell">'+(client||'')+'</td>' +
                       '<td class="opts-cell">'+(opts||'')+'</td>' +
                       '<td>' +
                         '<button type="button" class="btn btn-sm btn-secondary btn-edit-client">&#9998;</button> ' +
                         '<button type="button" class="btn btn-sm btn-danger btn-del-client">&times;</button>' +
                       '</td>';
        tbody.appendChild(tr);
        tr.querySelector('.btn-del-client').addEventListener('click', function() {
            tr.remove();
        });
        tr.querySelector('.btn-edit-client').addEventListener('click', function() {
            showClientModal(function(c,o){
                tr.querySelector('.client-cell').textContent = c;
                tr.querySelector('.opts-cell').textContent = o;
            }, client, opts);
        });
    }

    // hook up edit-export modal behaviors
    var exportsTable = document.getElementById('exportsTable');
    if (exportsTable) {
        exportsTable.querySelectorAll('tbody tr').forEach(function(row) {
            row.addEventListener('click', function() {
                var dir = row.getAttribute('data-dir');
                if (!dir) return;
                var info = window.exportClients && window.exportClients[dir];
                if (!info) info = {clients: []};
                var modal = document.getElementById('editExportModal');
                if (!modal) return;
                var dirLabel = modal.querySelector('#editExportDir');
                if (dirLabel) dirLabel.textContent = dir;
                var commentDiv = modal.querySelector('#editComment');
                if (commentDiv) commentDiv.textContent = info.comment || '';
                var tblBody = modal.querySelector('#editClientTable tbody');
                if (tblBody) tblBody.innerHTML = '';
                info.clients.forEach(function(c) {
                    addEditClientRow(c.client, c.opts);
                });
                var hidden = document.getElementById('replace_dir');
                if (hidden) hidden.value = dir;
                // clear fields that might persist from previous use
                var newLine = document.getElementById('new_line');
                if (newLine) newLine.value = '';
                var rem = document.getElementById('remove_export');
                if (rem) rem.value = '';
                // remember the original export line so delete can be accurate
                var origInput = document.getElementById('orig_line');
                if (origInput) {
                    var clientsArr = [];
                    info.clients.forEach(function(c){
                        if (c.client) {
                            if (c.opts) clientsArr.push(c.client + '(' + c.opts + ')');
                            else clientsArr.push(c.client);
                        }
                    });
                    origInput.value = dir + (clientsArr.length ? ' ' + clientsArr.join(' ') : '');
                }
                new bootstrap.Modal(modal).show();
            });
        });
    }
    var addEditBtn = document.getElementById('addEditClientBtn');
    if (addEditBtn) {
        addEditBtn.addEventListener('click', function() {
            showClientModal(function(c,o){ addEditClientRow(c,o); });
        });
    }
    var editForm = document.getElementById('editExportForm');
    if (editForm) {
        editForm.addEventListener('submit', function(e) {
            var dir = editForm.replace_dir.value.trim();
            if (!dir) return;
            var clients = [];
            editForm.querySelectorAll('#editClientTable tbody tr').forEach(function(row) {
                var c = row.querySelector('.client-cell').textContent.trim();
                var o = row.querySelector('.opts-cell').textContent.trim();
                if (c) {
                    if (o) clients.push(c + '(' + o + ')');
                    else clients.push(c);
                }
            });
            if (clients.length === 0) {
                e.preventDefault();
                showConfirmation('Please add at least one client entry.');
                return;
            }
            editForm.new_line.value = dir + ' ' + clients.join(' ');
        });

        // delete button behaviour ------------------------------------------------
        var delBtn = document.getElementById('deleteExportBtn');
        if (delBtn) {
            delBtn.addEventListener('click', function(e) {
                e.preventDefault();
                var orig = editForm.orig_line.value.trim();
                if (!orig) return;
                showConfirmation('Delete this export entry? This will remove the entire export from /etc/exports.', function() {
                    editForm.remove_export.value = orig;
                    editForm.submit();
                });
            });
        }
    }
    
    // samba share modal behavior ------------------------------------------------
    var createShareModal = document.getElementById('createShareModal');
    if (createShareModal) {
        createShareModal.addEventListener('show.bs.modal', function() {
            var form = document.getElementById('createShareForm');
            if (form) form.reset();
            // clear any existing option rows within this modal
            var tb = createShareModal.querySelector('.shareOptionTable tbody');
            if (tb) tb.innerHTML = '';
        });
    }
    // add-option button handler (works for both create and edit forms)
    document.querySelectorAll('.addOptionBtn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var modal = btn.closest('.modal');
            addOptionRow(modal);
        });
    });
    var shareTable = document.getElementById('sambaTable');
    if (shareTable) {
        shareTable.querySelectorAll('tbody tr').forEach(function(row) {
            row.addEventListener('click', function() {
                var name = row.getAttribute('data-share');
                if (!name) return;
                var info = window.sambaShares && window.sambaShares[name] || {};
                var modal = document.getElementById('editShareModal');
                if (!modal) return;
                var nameLabel = modal.querySelector('#editShareName');
                if (nameLabel) nameLabel.textContent = name;
                modal.querySelector('#old_share').value = name;
                modal.querySelector('#editShareNameInput').value = name;
                modal.querySelector('#editSharePath').value = info['path'] || '';
                modal.querySelector('#editShareComment').value = info['comment'] || '';
                // populate options table
                var tb = modal.querySelector('.shareOptionTable tbody');
                if (tb) {
                    tb.innerHTML = '';
                    Object.keys(info).forEach(function(k) {
                        if (k === 'path' || k === 'comment') return;
                        addOptionRow(modal, k, info[k]);
                    });
                }
                new bootstrap.Modal(modal).show();
            });
        });
    }
    var deleteShareBtn = document.getElementById('deleteShareBtn');
    if (deleteShareBtn) {
        deleteShareBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var form = document.getElementById('editShareForm');
            if (!form) return;
            var name = form.old_share.value.trim();
            if (!name) return;
            showConfirmation('Delete this Samba share? This will update smb.conf.', function() {
                // reuse existing remove_release name field
                var hidden = form.querySelector('input[name="remove_share_name"]');
                if (!hidden) {
                    hidden = document.createElement('input');
                    hidden.type = 'hidden';
                    hidden.name = 'remove_share_name';
                    form.appendChild(hidden);
                }
                hidden.value = name;
                form.remove_share = '1';
                // set a flag so server knows we want remove
                var flag = document.createElement('input');
                flag.type = 'hidden';
                flag.name = 'remove_share';
                flag.value = '1';
                form.appendChild(flag);
                form.submit();
            });
        });
    }

    // unmount buttons in mounts table
    var umountRowButtons = document.querySelectorAll('.btn-umount-row');
    umountRowButtons.forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Unmount this export?\nAny users accessing it will be disconnected.', function() {
                // programmatic submit() does not include button name/value, so
                // add a hidden field just as the mount code does.
                if (!btn.form.querySelector('input[name="umount_lv"]')) {
                    var hid = document.createElement('input');
                    hid.type = 'hidden';
                    hid.name = 'umount_lv';
                    hid.value = btn.value || '1';
                    btn.form.appendChild(hid);
                }
                btn.form.submit();
            });
        });
    });

});

// fallback VG button binder in case DOMContentLoaded handlers missed it
(function(){
    var btn = document.getElementById('btnShowVgModal');
    if (btn) {
        btn.addEventListener('click', function() {
            new bootstrap.Modal(document.getElementById('vgModal')).show();
        });
    }
})();

// global cleanup: remove any orphaned backdrops once the last modal closes
// (addresses situations where closing via the X leaves a grey overlay).
document.addEventListener('hidden.bs.modal', function() {
    // defer slightly to allow Bootstrap's own handlers to run first
    setTimeout(function() {
        if (document.querySelectorAll('.modal.show').length === 0) {
            document.querySelectorAll('.modal-backdrop').forEach(function(b){ b.remove(); });
        }
    }, 10);
});

// disk table row info/selection (run regardless of DOMContentLoaded state)
(function() {
    var diskTable = document.getElementById('diskTable');
    // no need to spam console when missing (only present on disks view)
    if (diskTable) {
        diskTable.querySelectorAll('tbody tr').forEach(function(row) {
            row.addEventListener('click', function() {
                var dev = row.dataset.dev || '';
                console.log('disk row clicked', dev);
                // request card HTML via AJAX
                fetchAuth('views/disks.php?ajax=1&disk=' + encodeURIComponent(dev))
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

// raid table row info/selection (similar pattern)
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

// display any message that was provided by PHP via a hidden element
window.addEventListener('DOMContentLoaded', function() {
    // debugging persisted state from before reload
    // cleared debug flag from prior runs
    try { localStorage.removeItem('mountDebug'); } catch(e) {}
    var msgEl = document.getElementById('initialMessage');
    if (msgEl) {
        var text = msgEl.innerHTML;
        var reopenVg = msgEl.dataset.reopenVg === '1';
        var reopenLv = msgEl.dataset.reopenLv === '1';
        var reload = msgEl.dataset.reload === '1';
        if (text) {
            showConfirmation(text, function() {
                if (reopenVg) {
                    new bootstrap.Modal(document.getElementById('vgModal')).show();
                }
                if (reopenLv) {
                    new bootstrap.Modal(document.getElementById('lvModal')).show();
                }
                if (reload) {
                    // ensure the table reflects any changes (e.g. raid removed)
                    window.location.reload();
                }
            });
        } else {
            if (reopenVg) {
                new bootstrap.Modal(document.getElementById('vgModal')).show();
            }
            if (reopenLv) {
                new bootstrap.Modal(document.getElementById('lvModal')).show();
            }
            if (reload) {
                window.location.reload();
            }
        }
        msgEl.parentNode.removeChild(msgEl);
    }
});
