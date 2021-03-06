<?php

global $minimum_btc_amount, $minimum_fiat_amount;

$minimum_btc_amount = numstr_to_internal(MINIMUM_BTC_AMOUNT);
$minimum_fiat_amount = numstr_to_internal(MINIMUM_FIAT_AMOUNT);

function active_table_cell_trade($id, $index, $content, $url, $right=false)
{
    printf("<td id='$id$index' class='active%s' %s %s %s>%s</td>\n",
           $right ? " right" : "",
           'onmouseover="ObjById(\''.$id.'l\').style.backgroundColor=\'#8ae3bf\'; ObjById(\''.$id.'r\').style.backgroundColor=\'#8ae3bf\';"',
           'onmouseout="ObjById(\''.$id.'l\').style.backgroundColor=\'#7ad3af\'; ObjById(\''.$id.'r\').style.backgroundColor=\'#7ad3af\';"',
           "onclick=\"document.location='$url';\"",
           $content);
}

function show_mini_orderbook_table_cell($id, $curr, $price, $have, $want, $fiat_depth, $btc_depth)
{
    // $have and $want is what the 'worst priced' existing order has and wants, and is used here to set the price
    // $fiat_depth and $btc_depth are combined amounts available which we want to match, and may include orders at better prices
    // $curr is the currency type they want

    if ($curr == 'BTC') {
        // we are selling BTC
        $depth = $btc_depth;
        $p = clean_sql_numstr(bcdiv($have, $want, 8));
    } else {
        // we are buying BTC
        $depth = $fiat_depth;
        $p = clean_sql_numstr(bcdiv($want, $have, 8));
    }

    list ($w, $r) = gmp_div_qr(gmp_mul($depth, $have), $want);
    $w = gmp_strval(gmp_cmp($r, 0) ? gmp_sub($w, 1) : $w);
    $h = gmp_strval($depth);

    active_table_cell_trade($id, 'l', internal_to_numstr($btc_depth,  BTC_PRECISION),  "?page=trade&in=$curr&have=$h&want=$w&rate=$p", 'right');
    active_table_cell_trade($id, 'r', internal_to_numstr($fiat_depth, FIAT_PRECISION), "?page=trade&in=$curr&have=$h&want=$w&rate=$p", 'right');
}

function show_mini_orderbook_table_row($id, $curr, $price, $have, $want, $this_fiat, $this_btc, $sum_fiat, $sum_btc, $mine)
{
    global $minimum_btc_amount, $minimum_fiat_amount;

    $this_btc_str = internal_to_numstr($this_btc, BTC_PRECISION);
    $this_fiat_str = internal_to_numstr($this_fiat, FIAT_PRECISION);

    if (string_is_zero($this_btc_str) ||
        string_is_zero($this_fiat_str) ||
        gmp_cmp($this_btc, $minimum_btc_amount) < 0 ||
        gmp_cmp($this_fiat, $minimum_fiat_amount) < 0)
        return;

    if ($mine) {
        $sum_btc_str = internal_to_numstr($sum_btc, BTC_PRECISION);
        $sum_fiat_str = internal_to_numstr($sum_fiat, FIAT_PRECISION);

        active_table_row("me", "?page=view_order&orderid=$mine");
        echo "<td class='right'>$price</td>\n";
        echo "<td class='right'>$this_btc_str</td>\n";
        echo "<td class='right'>$this_fiat_str</td>\n";
        echo "<td class='right'>$sum_btc_str</td>\n";
        echo "<td class='right'>$sum_fiat_str</td>\n";
        echo "</tr>\n";
    } else {
        echo "<tr>\n";
        echo "<td class='right'>$price</td>\n";
        show_mini_orderbook_table_cell($id.'t', $curr, $price, $have, $want, $this_fiat, $this_btc);
        show_mini_orderbook_table_cell($id.'c', $curr, $price, $have, $want, $sum_fiat,  $sum_btc);
    }
    echo "</tr>\n";
}

function show_mini_orderbook_table($bids)
{
    global $buy, $sell, $is_logged_in;

    echo "<table class='display_data'>";
    echo "<tr><th colspan=5 class='center'>" .
        ($bids
         ? sprintf(_("People Buying BTC for %s"),   CURRENCY)
         : sprintf(_("People Selling BTC for %s" ), CURRENCY)) .
        "</th></tr>" .
        "<tr><th valign='bottom' class='right'>" .
        sprintf(_("Price"), CURRENCY) . "</th>" .
        "<th colspan=2 valign='bottom' class='center'>" . _("Depth") . "</th>" .
        "<th colspan=2 valign='bottom' class='center'>" . _("Cumulative Depth") . "</th>" .
        "</tr>";
    echo "<tr><th></th><th class='center'>BTC</th><th class='center'>" . CURRENCY . "</th><th class='center'>BTC</th><th class='center'>" . CURRENCY . "</th></tr>\n";

    $limit = '';
    if ($bids) {
        $price = "amount/want_amount";
        $amount = "want_amount";
        $want_type = "BTC";
        $order = "DESC";
        if ($buy) $limit = "AND $price > " . $buy * (1 - ORDERBOOK_PRICE_RANGE_PERCENTAGE/100);
    } else {
        $price = "want_amount/amount";
        $amount = "amount";
        $want_type = CURRENCY;
        $order = "ASC";
        if ($sell) $limit = "AND $price < " . $sell * (1 + ORDERBOOK_PRICE_RANGE_PERCENTAGE/100);
    }

    $result = do_query("
        SELECT
            uid = $is_logged_in as me,
            orderid,
            amount,
            want_amount
        FROM
            orderbook
        WHERE
            want_type = '$want_type' AND
            status='OPEN'
            $limit
        ORDER BY
            $price $order, timest ASC
    ");

    $last_price = $fiat_amount_at_price = $btc_amount_at_price = $total_fiat_amount = $total_btc_amount = "0";
    $mine = $mine_count = 0;
    while ($row = mysql_fetch_array($result)) {
        $have_amount = $row['amount'];
        $want_amount = $row['want_amount'];
        $orderid = $row['orderid'];

        if ($bids) {
            $btc_amount  = $want_amount;
            $fiat_amount = $have_amount;
        } else {
            $btc_amount  = $have_amount;
            $fiat_amount = $want_amount;
        }

        $price = fiat_and_btc_to_price($fiat_amount, $btc_amount, $bids ? 'down' : 'up');

        if ($price == $last_price) {
            $fiat_amount_at_price = gmp_add($fiat_amount_at_price, $fiat_amount);
            $btc_amount_at_price  = gmp_add($btc_amount_at_price,  $btc_amount);
        } else {
            if ($last_price) {
                show_mini_orderbook_table_row($last_orderid, $want_type, $last_price, $last_have, $last_want, $fiat_amount_at_price, $btc_amount_at_price, $total_fiat_amount, $total_btc_amount, $mine);
                $mine = 0;
            }
                                              
            $last_price = $price;
            $fiat_amount_at_price = $fiat_amount;
            $btc_amount_at_price  = $btc_amount;
        }

        $last_have = $have_amount;
        $last_want = $want_amount;
        $last_orderid = $orderid;

        $total_fiat_amount = gmp_add($total_fiat_amount, $fiat_amount);
        $total_btc_amount  = gmp_add($total_btc_amount,  $btc_amount);

        if ($row['me']) {
            $mine = $orderid;
            $mine_count++;
        }
    }

    if ($last_price)
        show_mini_orderbook_table_row($last_orderid, $want_type, $last_price, $last_have, $last_want, $fiat_amount_at_price, $btc_amount_at_price, $total_fiat_amount, $total_btc_amount, $mine);

    echo "</table>\n";

    return $mine_count;
}

function show_mini_orderbook()
{
    echo "<center><table><tr><td>\n";
    $mine = show_mini_orderbook_table(true);
    echo "</td><td>";
    $mine += show_mini_orderbook_table(false);
    echo "</td></tr></table></center>";
    echo "<p>" . sprintf(_("Showing all orders within %s%% of the best price."), ORDERBOOK_PRICE_RANGE_PERCENTAGE) . "</p>\n";

    if ($mine)
        echo "<p>" . _("The bold lines indicate prices at which you have orders.  Note that other users may have orders at the same price, and so the depth shown isn't necessarily all due to your order.  You can click the bold lines to view or cancel your orders.") . "</p>\n";
}

    if (isset($_GET['have']))
        $in_amount = internal_to_numstr(get('have'));
    else
        $in_amount = '';

    if (isset($_GET['want']))
        $out_amount = internal_to_numstr(get('want'));
    else
        $out_amount = '';

    if (isset($_GET['rate']))
        $rate = get('rate');
    else
        $rate = '';
?>
        <div class='content_box'>
            <h3><?php echo _("Currency converter"); ?></h3>

<?php if (!$is_logged_in) { ?>
        <p><b><?php printf(_("%s allows you to trade %s for Bitcoins (BTC) or BTC for %s with other users."),
                           SITE_NAME,
                           CURRENCY_FULL_PLURAL . " (" . CURRENCY . ")",
                           CURRENCY); ?></a></b></p>
<?php } ?>

        <form id='buy_form' action='?page=place_order' method='post'>
            <table id='exchanger'>
            <tr><td>

            <p><b><?php echo _("Currency I have"); ?>:</b></p>
            <div class='currbox_wrapper'>
                <div id='incurrency' class='currbox' onclick='javascript:rolldown_in();'>
                    <div class='currbox_right'>
                        <b class='currcode'><?php echo CURRENCY; ?></b>
                        <img src='images/arrow_down.png' />
                    </div>

                    <img class='currflag' src='images/<?php echo strtolower(CURRENCY) ?>_flag.png' />
                    <span class='currname'><?php echo CURRENCY_FULL; ?></span>
                </div>
                <div id='currsel_in'>
                    <div class='currsel_entry' onclick='javascript:select_currency_in(this, true);'>
                        <div class='currbox_right'>
                            <b class='currcode'><?php echo CURRENCY; ?></b>
                        </div>
                        <img class='currflag' src='images/<?php echo strtolower(CURRENCY) ?>_flag.png' />
                        <span class='currname'><?php echo CURRENCY_FULL; ?></span>
                    </div>
                    <div class='currsel_entry' onclick='javascript:select_currency_in(this, true);'>
                        <div class='currbox_right'>
                            <b class='currcode'>BTC</b>
                        </div>
                        <img class='currflag' src='images/btc_flag.png' />
                        <span class='currname'>Bitcoin</span>
                    </div>
                </div>
            </div>
        </td>
        <td>
            <p><b><?php echo _("Currency I want"); ?>:</b></p>
            <div class='currbox_wrapper'>
                <div id='outcurrency' class='currbox' onclick='javascript:rolldown_out();'>
                    <div class='currbox_right'>
                        <b class='currcode'>btc</b>
                        <img src='images/arrow_down.png' />
                    </div>

                    <img class='currflag' src='images/btc_flag.png' />
                    <span class='currname'>Bitcoin</span>
                </div>
                <div id='currsel_out'>
                    <div class='currsel_entry' onclick='javascript:select_currency_out(this, true);'>
                        <div class='currbox_right'>
                            <b class='currcode'><?php echo CURRENCY; ?></b>
                        </div>
                        <img class='currflag' src='images/<?php echo strtolower(CURRENCY) ?>_flag.png' />
                        <span class='currname'><?php echo CURRENCY_FULL; ?></span>
                    </div>
                    <div class='currsel_entry' onclick='javascript:select_currency_out(this, true);'>
                        <div class='currbox_right'>
                            <b class='currcode'>BTC</b>
                        </div>
                        <img class='currflag' src='images/btc_flag.png' />
                        <span class='currname'>Bitcoin</span>
                    </div>
                </div>
            </div>
        </td>
        </tr>

            <tr>
            <td>
            <input id='inamount' autocomplete='off' name='amount' class='curramount' type="text" size="20" value="<?php echo $in_amount?>" onkeyup='typed_amount_in(event);'>
            </td>

            <td>
            <input id='outamount' autocomplete='off' name='want_amount' class='curramount' type="text" size="20" value="<?php echo $out_amount?>" onkeyup='typed_amount_out(event);'>
            </td>
            </tr>
        <tr><td>
            <b>Price:</b>
            <input id='price' autocomplete='off' name='price' class='price' type="text" size="20" value="<?php echo $rate?>" onkeyup='typed_amount_price(event);'>
        </td><td>
    <?php
    if ($is_logged_in) { ?>
                    <input type='hidden' name='csrf_token' value="<?php echo $_SESSION['csrf_token']; ?>" />
                    <input type='hidden' name='type' value='' />
                    <input type='hidden' name='want_type' value='' />
                    <input type='submit' onclick='return buy_clicked();' value='<?php echo _("Place Order"); ?>' />
    <?php } ?>
        </td></tr>
        </table>
        </form>

    <?php
    if ($is_logged_in)
        echo "<p>" .
            sprintf(_("You have %s."), balances_text($is_logged_in)) .
            " " .
            _("You can click the 'Depth' amounts below to automatically fill the above form.") .
            "</p>\n";
    else
        echo "<p>" .
            _("To begin trading you will need an OpenID account.") .
            "</p><p>" .
            _("If you do not have an OpenID login then we recommend") . " " . '<a href="https://www.myopenid.com/">MyOpenID</a>' .
            "</p><p>" .
            _("This is a Two-Factor Authentication Security Supported Exchange, for more Info see our help section.") .
            "</p>";

    show_mini_orderbook();
?>
        </div>

        <div class='content_box'>
            <h3><?php echo _("Bitcoin"); ?></h3>
            <p>
            <?php printf(_("%sBitcoin%s is an emerging crypto-currency that offers many exciting possibilities. See %sBitcoin Consultancy%s for Bitcoin related projects and questions."),
                         '<a target="_blank" href="http://bitcoin.org">',
                         '</a>',
                         '<a target="_blank" href="http://bitcoinconsultancy.com/">',
                         '</a>'); ?>
            </p>
        </div>

        <div class='content_box'>
            <h3><?php echo _("Commission"); ?></h3>
<?php
    if (COMMISSION_PERCENTAGE_FOR_FIAT == 0 && COMMISSION_PERCENTAGE_FOR_BTC == 0)
        echo "<p>" . _("All trades are free of commission") . "</p>\n";
    else {
        echo "<p>" . _("Commission is charged at the following rates") . ":</p>\n";
        show_commission_rates();
    }
?>
        </div>

<div class='content_box'>
<?php show_contact_info(); ?>
        </div>
