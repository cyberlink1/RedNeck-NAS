<?php
// Legacy redirect for RAID-specific page.  Previously the combined LVM/RAID
// interface lived here; it's now split into two dashboard views.
header('Location: dashboard.php?view=raid');
exit;
