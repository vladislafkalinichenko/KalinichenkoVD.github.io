<?php
declare(strict_types=1);
// Preserve old bookmarks while maintaining a single public news section.
header('Location: ../rss.html?source=own',true,301);
exit;
