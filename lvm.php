<?php
// lvm.php is deprecated in favour of dashboard.php?view=lvm.
// RAID-specific functionality has been moved to a separate view (see raid.php).
// This stub simply redirects; the real implementation lives under views/lvm.php.
header('Location: dashboard.php?view=lvm');
exit;
