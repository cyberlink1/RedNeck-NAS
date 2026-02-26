// LVM view specific behaviors (VG/LV modals, snapshots, extension, etc.)

document.addEventListener('DOMContentLoaded', function() {
    // volume-group modal button
    var showVgBtn = document.getElementById('btnShowVgModal');
    if (showVgBtn) {
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
            var modal = document.getElementById('removeLvModal');
            if (modal) {
                var title = modal.querySelector('.modal-title');
                if (title) {
                    title.textContent = selected.length > 1 ? 'Remove Logical Volumes' : 'Remove Logical Volume';
                }
            }
            var sel = form.querySelector('select[name="lv_select"]');
            var listContainer = form.querySelector('#removeLvList');
            if (listContainer) listContainer.innerHTML = '';
            if (sel) {
                sel.value = '';
                sel.closest('.mb-3').style.display = '';
                sel.required = true;
                sel.disabled = false;
            }
            form.querySelectorAll('input[name="lvs[]"]').forEach(function(i){ i.remove(); });

            selected.forEach(function(lv){
                var hid = document.createElement('input');
                hid.type = 'hidden'; hid.name = 'lvs[]'; hid.value = lv;
                form.appendChild(hid);
            });
            if (selected.length === 1) {
                if (sel) {
                    sel.value = selected[0];
                    sel.required = true;
                    sel.disabled = false;
                }
            } else {
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

    // enable clicking on PV rows to show details (delegated handler)
    var pvTable = document.getElementById('pvTable');
    if (pvTable) {
        pvTable.addEventListener('click', function(e) {
            var row = e.target.closest('tr.pv-row');
            if (!row) return;
            var pv = row.dataset.pv || '';
            console.log('PV row clicked', pv);
            if (!pv) return;
            fetchAuth('views/lvm.php?ajax=1&pv=' + encodeURIComponent(pv))
                .then(function(resp){ return resp.text(); })
                .then(function(html){
                    console.log('PV AJAX response', html);
                    showInfo(html);
                })
                .catch(function(err){ console.error('pv AJAX error', err); });
        });
    }

    // confirm format/remove forms for logical volumes
    var formatLvForm = document.getElementById('formatLvForm');
    if (formatLvForm) {
        formatLvForm.addEventListener('submit', function(e) {
            e.preventDefault();
            showConfirmation('Format the selected logical volume? Any data on it will be lost.', function() {
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
                var inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'extend_lv'; inp.value = '1';
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
                var inp = document.createElement('input'); inp.type = 'hidden'; inp.name = 'rename_lv'; inp.value = '1';
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
    // snapshot logic
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

    // PV action forms
    var checkPvForm = document.getElementById('checkPvForm');
    if (checkPvForm) {
        checkPvForm.addEventListener('submit', function(e) {
            e.preventDefault();
            showConfirmation('Run consistency check on the physical volume?', function() {
                var inp = document.createElement('input'); inp.type='hidden'; inp.name='check_pv'; inp.value='1';
                checkPvForm.appendChild(inp);
                showSpinner(); checkPvForm.submit();
            });
        });
    }
    var repairPvForm = document.getElementById('repairPvForm');
    if (repairPvForm) {
        repairPvForm.addEventListener('submit', function(e) {
            e.preventDefault();
            showConfirmation('Attempt repair of the physical volume?', function() {
                var inp = document.createElement('input'); inp.type='hidden'; inp.name='repair_pv'; inp.value='1';
                repairPvForm.appendChild(inp);
                showSpinner(); repairPvForm.submit();
            });
        });
    }
    var movePvForm = document.getElementById('movePvForm');
    if (movePvForm) {
        movePvForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var dest = movePvForm.dest_pv.value;
            if (!dest) {
                showConfirmation('Please select a destination PV.');
                return;
            }
            showConfirmation('Move data from ' + movePvForm.pv.value + ' to ' + dest + '?', function() {
                var inp = document.createElement('input'); inp.type='hidden'; inp.name='move_pv'; inp.value='1';
                movePvForm.appendChild(inp);
                showSpinner(); movePvForm.submit();
            });
        });
        // disable current pv option when showing
        var mvModal = document.getElementById('movePvModal');
        if (mvModal) {
            mvModal.addEventListener('show.bs.modal', function() {
                var src = movePvForm.pv.value;
                var sel = movePvForm.dest_pv;
                if (sel) {
                    Array.from(sel.options).forEach(function(opt) {
                        opt.disabled = (opt.value === src || opt.value === '');
                    });
                }
            });
        }
    }
    var resizePvForm = document.getElementById('resizePvForm');
    if (resizePvForm) {
        resizePvForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var size = resizePvForm.new_size.value;
            if (!size) {
                showConfirmation('Please specify a new size.');
                return;
            }
            showConfirmation('Resize ' + resizePvForm.pv.value + ' to ' + size + '?', function() {
                var inp = document.createElement('input'); inp.type='hidden'; inp.name='resize_pv'; inp.value='1';
                resizePvForm.appendChild(inp);
                showSpinner(); resizePvForm.submit();
            });
        });
    }
    var removePvForm = document.getElementById('removePvForm');
    if (removePvForm) {
        removePvForm.addEventListener('submit', function(e) {
            e.preventDefault();
            showConfirmation('Remove the selected physical volume? This is irreversible.', function() {
                var inp = document.createElement('input'); inp.type='hidden'; inp.name='remove_pv'; inp.value='1';
                removePvForm.appendChild(inp);
                showSpinner(); removePvForm.submit();
            });
        });
    }
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
            document.querySelectorAll('#vgModal .vg-checkbox').forEach(function(cb) {
                cb.addEventListener('change', function() {
                    if (!cb.checked && selAll) selAll.checked = false;
                });
            });
        });
    }

    // intercept "extend" buttons
    var extendBtn = document.querySelector('#extendVgModal button[name="extend_vg"]');
    if (extendBtn) {
        extendBtn.addEventListener('click', function(e) {
            e.preventDefault();
            showConfirmation('Add selected physical volumes to the volume group? This will modify the VG.', function() {
                var vgName = document.querySelector('#extendVgForm input[name="vg_name"]').value;
                var selected = Array.from(document.querySelectorAll('#extendVgForm input[name="pvs[]"]:checked')).map(function(ch){return ch.value;});
                var topForm = document.createElement('form');
                topForm.method = 'post';
                topForm.style.display = 'none';
                var inp = document.createElement('input');
                inp.type = 'hidden'; inp.name = 'vg_name'; inp.value = vgName;
                topForm.appendChild(inp);
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
    var extendMultiBtn = document.querySelector('#extendSelectedVgModal button[name="extend_vg_multi"]');
    if (extendMultiBtn) {
        extendMultiBtn.addEventListener('click', function(e) {
            e.preventDefault();
            var anyChecked = document.querySelectorAll('#extendSelectedForm input[type="checkbox":checked]').length > 0;
            if (!anyChecked) {
                showConfirmation('Please select at least one physical volume to add before submitting.');
                return;
            }
            showConfirmation('Add selected physical volumes to the chosen volume groups? This will modify the VGs.', function() {
                var topForm = document.createElement('form');
                topForm.method = 'post';
                topForm.style.display = 'none';
                document.querySelectorAll('#extendSelectedForm input[name="vg_name[]"]').forEach(function(inp) {
                    var h = document.createElement('input');
                    h.type = 'hidden'; h.name = 'vg_name[]'; h.value = inp.value;
                    topForm.appendChild(h);
                });
                document.querySelectorAll('#extendSelectedForm input[type="checkbox":checked]').forEach(function(inp) {
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

    // ensure parent modals reappear when submodals close
    ['createVgModal','extendVgModal','extendSelectedVgModal'].forEach(function(id) {
        var m = document.getElementById(id);
        if (m) {
            m.addEventListener('hidden.bs.modal', function() {
                if (window._confirmActive) return;
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
                if (window._confirmActive) return;
                var lv = document.getElementById('lvModal');
                var bs = bootstrap.Modal.getInstance(lv) || new bootstrap.Modal(lv);
                bs.show();
            });
        }
    });
});

// helper used by snapshot logic in this file
function loadSnapshots(lv) {
    var tableBody = document.getElementById('snapListBody');
    if (!tableBody) return;
    tableBody.innerHTML = '';
    return fetchAuth('dashboard.php?view=lvm&ajax=list_snaps&lv=' + encodeURIComponent(lv))
        .then(r => r.text())
        .then(txt => {
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
