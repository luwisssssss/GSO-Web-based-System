<?php
// R2B: online Adviser routing/actions are retired. Historical data is preserved.
require_once dirname(__DIR__,3)."/includes/borrower_check.php";
http_response_code(410);
header("Content-Type: text/plain; charset=UTF-8");
header("Cache-Control: no-store");
exit("Online Adviser processing has been retired. Obtain required signatures manually and upload the signed facility request letter.");
