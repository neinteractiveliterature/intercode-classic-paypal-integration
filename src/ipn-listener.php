<?
// PayPal Instant Payment Notifications Listener for Intercon
//
// NOTE: This code acts as a replacement for the existing PayPal integration features in the Intercon site.
// Because PayPal only allows a single IPN endpoint URL per account, and the database settings will vary
// depending on which con is being paid for, we use an include path trick based on parsing the item_name
// field from the POST to include the appropriate intercon_db.inc file.
//
// Yes, this is ugly as sin.  I'm sorry.
// - Nat

define(HOME_DIR, "/var/www");
define(NOTIFICATION_EMAIL, 'barry.tannenbaum@gmail.com');

function http_error($msg, $status=400) {
  header("Status: $status", true, $status);
  echo $msg;
  echo "\n";
  echo mysql_error();
  echo "\n";
  die();
}

/*
 * log_paypal_msgs
 *
 * Send a copy of the PayPal messages home, so we can try to figure out
 * what's going on
 */

function log_paypal_msgs ($subj)
{
  // Dump the POST array to the message

  $msg = "POST Parameters:\n";
  reset ($_POST);
  foreach ($_POST as $k => $v)
  {
    $msg .= "[$k] = $v\n";
  }
  reset ($_POST);

  // And the SESSION array

  $msg .= "\nSESSION Parameters:\n";
  reset ($_SESSION);
  foreach ($_SESSION as $k => $v)
  {
    $msg .= "[$k] = $v\n";
  }
  reset ($_SESSION);

  // And the SERVER array

  $msg .= "\nSERVER Parameters:\n";
  reset ($_SERVER);
  foreach ($_SERVER as $k => $v)
  {
    $msg .= "[$k] = $v\n";
  }
  reset ($_SERVER);

  // Phone home

  intercon_mail(NOTIFICATION_EMAIL, '[PayPal Log] ' . $subj, $msg);
}

/*
 * mark_user_paid
 *
 * If the user has just paid through PayPal, update his status
 */

function mark_user_paid ()
{
  //  dump_array ('POST - mark_user_paid', $_POST);

  // Flip the "Paid" bit in the user's record

  $paid_by = 'Paid via PayPal ' . strftime ('%d-%b-%Y %H:%M');
  if (array_key_exists ('txn_id', $_POST))
    $paid_by .= ' TxnID: ' . $_POST['txn_id'];
  if (array_key_exists ('last_name', $_POST))
  {
    $paid_by .= ' PaidBy: ' . $_POST['last_name'];
    if (array_key_exists ('first_name', $_POST))
      $paid_by .= ', ' . $_POST['first_name'];
  }

  $amount = 0;
  if (array_key_exists ('mc_gross', $_POST))
    $amount = intval ($_POST['mc_gross'] * 100);

  // There are two types of payment that may come in here; con registration and
  // shirt payments.  We can differentiate them by the "item_name" field.

  if (! array_key_exists ('item_name', $_POST))
  {
    //    http_error('PayPal message does not contain "item_name" field.  We can\'t tell what\'s being paid for!');
    log_paypal_msgs('No item_name - Do not know what has been paid for!');
    return;
  }

  $user_id = 0;

  $bConPayment = $_POST['item_name'] == PAYPAL_ITEM_CON;
  $bShirtPayment = $_POST['item_name'] == PAYPAL_ITEM_SHIRT;
  $bThursdayPayment = $_POST['item_name'] == PAYPAL_ITEM_THURSDAY;
  $bDeadDogPayment = $_POST['item_name'] == PAYPAL_ITEM_DEAD_DOG;

  /*
  if ($bConPayment)
    echo "<!-- Con payment -->\n";
  if ($bShirtPayment)
    echo "<!-- Shirt payment -->\n";
  if ($bThursdayPayment)
    echo "<!-- Thursday payment -->\n";
  */

  if ($bConPayment)
  {
    // If this is a con payment, the custom field is the UserId.  Mark the
    // user paid

    if (array_key_exists ('custom', $_POST))
      $user_id = intval ($_POST['custom']);

    $sql = "UPDATE Users SET CanSignup='Paid'";
    $sql .= ', CanSignupModified=NULL';
    $sql .= ", CanSignupModifiedId=$user_id";
    $sql .= ", PaymentNote='$paid_by'";
    $sql .= ", PaymentAmount=$amount";
    $sql .= " WHERE UserId=$user_id";
    //  echo "$sql<p>\n";
    $result = mysql_query ($sql);
    if (! $result)
      http_error("Failed to update user $user_id with notification from PayPal");
  }
  elseif ($bShirtPayment)
  {
    // If this is a shirt payment, the custom field is the OrderId.  Mark the
    // shirt paid and fetch the UserId from the StoreOrders record

    $OrderId = 0;

    if (array_key_exists ('custom', $_POST))
      $OrderId = intval ($_POST['custom']);

    $sql = 'UPDATE StoreOrders SET Status="Paid"';
    $sql .= ", PaymentNote='$paid_by'";
    $sql .= ", PaymentCents=$amount";
    $sql .= " WHERE OrderId=$OrderId";
    //  echo "$sql<p>\n";
    $result = mysql_query ($sql);
    if (! $result)
      http_error("Failed to update StoreOrders record $OrderId with notification from PayPal");
  }
  elseif ($bThursdayPayment)
  {
    // If this is a Thursday Thing payment, the custom field is the UserId.
    // Add a Thursday record for the user

    if (array_key_exists ('custom', $_POST))
      $user_id = intval ($_POST['custom']);

    $sql = "INSERT Thursday SET UserId=$user_id,";
    $sql .= 'Status="Paid", ';
    $sql .= "PaymentNote='$paid_by', ";
    $sql .= "PaymentAmount=$amount ";
    $sql .= "ON DUPLICATE KEY UPDATE ";
    $sql .= "Status=values(Status), ";
    $sql .= "PaymentNote=values(PaymentNote), ";
    $sql .= "PaymentAmount=values(PaymentAmount)";

    $result = mysql_query($sql);
    if (! $result)
      http_error("Failed to insert Thursday record for $user_id", $sql);
  }
  elseif ($bDeadDogPayment) {
     // If this is a Dead Dog payment, the custom field is the UserId.
     // Add a Dead Dog record for the user

     if (array_key_exists ('custom', $_POST))
       $user_id = intval ($_POST['custom']);

     $quantity = intval ($_POST['quantity']);
     $txn_id = $_POST['txn_id'];

     $result = mysql_query("SELECT PaymentId FROM DeadDog WHERE TxnId='".mysql_escape_string($txn_id)."'");
     if (! $result) {
         http_error("Failed to query DeadDog records for $txn_id", $sql);
     }
     $idRow = mysql_fetch_row($result);

     if ($idRow == null) {
         $existingId = null;
     } else {
         $existingId = $idRow[0];
     }

     if ($existingId == null) {
         $sql = "INSERT DeadDog SET ";
     } else {
         $sql = "UPDATE DeadDog SET ";
     }
     $sql .= "UserId=$user_id,";
     $sql .= 'Status="Paid", ';
     $sql .= "TxnId='".mysql_escape_string($txn_id)."', ";
     $sql .= "PaymentNote='$paid_by', ";
     $sql .= "PaymentAmount=$amount, ";
     $sql .= "Quantity=$quantity";
     if ($existingId != null) {
         $sql .= " WHERE PaymentId=".$existingId;
     }

     $result = mysql_query($sql);
     if (! $result)
       http_error("Failed to insert DeadDog record for $user_id", $sql);
  }
  else
  {
    log_paypal_msgs ('Unknown payment type');
    http_error("Unknown payment type! " . $_POST['item_name']);
  }
}

// read the post from PayPal system and add 'cmd'
$req = 'cmd=_notify-validate';

foreach ($_POST as $key => $value) {
  $value = urlencode(stripslashes($value));
  $req .= "&$key=$value";
}


$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, "https://www.paypal.com/cgi-bin/webscr");
curl_setopt($ch, CURLOPT_POST, 1);
curl_setopt($ch, CURLOPT_POSTFIELDS, $req);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, false);
curl_setopt($ch, CURLOPT_USERAGENT, "cURL/PHP");
$res = curl_exec($ch);

$item_name = $_POST['item_name'];
if (preg_match('/^NELCO/', $item_name)) {
  // NELCO stuff is handled manually, just acknowledge this and move on
  exit;
}

preg_match('/^Intercon (.)/', $item_name, $matches);
$con_id = $matches[1];
if ($con_id == "" || $con_id === NULL) {
  http_error("Couldn't determine the convention ID from item name $item_name");
}

if ($con_id == "0") {
  $con_dir = HOME_DIR . "/sandbox.interactiveliterature.org/current";
} else {
  $con_dir = HOME_DIR . "/interactiveliterature.org/$con_id";
}

ini_set(
  'include_path',
  ini_get( 'include_path' ) . PATH_SEPARATOR . $con_dir
);

include ("intercon_db.inc");
if (! intercon_db_connect ())
{
  http_error('Failed to establish connection to the database');
}

if (!$res) {
  http_error("Couldn't connect to PayPal for verification", 500);
} else {

  if (strcmp ($res, "VERIFIED") == 0) {
    // Check whether PayPal is notifying us that they've accepted payment for us

    if (array_key_exists ('payment_status', $_POST)) {
      if (('Completed' == $_POST['payment_status']))
        mark_user_paid ();
    } else {
      log_paypal_msgs('No payment_status');
    }
    // check that txn_id has not been previously processed
    // check that receiver_email is your Primary PayPal email
    // check that payment_amount/payment_currency are correct
    // process payment
  } else if (strcmp ($res, "INVALID") == 0) {
    log_paypal_msgs ('INVALID result');
    http_error("PayPal said the transaction was invalid", 403);
  } else {
    http_error("PayPal returned unknown validation: $res", 406);
  }
}
?>
