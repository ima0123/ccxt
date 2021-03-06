<?php

namespace ccxt;

class lykke extends Exchange {

    public function describe () {
        return array_replace_recursive (parent::describe (), array (
            'id' => 'lykke',
            'name' => 'Lykke',
            'countries' => 'CH',
            'version' => 'v1',
            'rateLimit' => 200,
            'has' => array (
                'CORS' => false,
                'fetchOHLCV' => false,
                'fetchTrades' => false,
            ),
            'requiredCredentials' => array (
                'apiKey' => true,
                'secret' => false,
            ),
            'urls' => array (
                'logo' => 'https://user-images.githubusercontent.com/1294454/34487620-3139a7b0-efe6-11e7-90f5-e520cef74451.jpg',
                'api' => array (
                    'mobile' => 'https://api.lykkex.com/api',
                    'public' => 'https://hft-api.lykke.com/api',
                    'private' => 'https://hft-api.lykke.com/api',
                    'test' => array (
                        'mobile' => 'https://api.lykkex.com/api',
                        'public' => 'https://hft-service-dev.lykkex.net/api',
                        'private' => 'https://hft-service-dev.lykkex.net/api',
                    ),
                ),
                'www' => 'https://www.lykke.com',
                'doc' => array (
                    'https://hft-api.lykke.com/swagger/ui/',
                    'https://www.lykke.com/lykke_api',
                ),
                'fees' => 'https://www.lykke.com/trading-conditions',
            ),
            'api' => array (
                'mobile' => array (
                    'get' => array (
                        'AllAssetPairRates/{market}',
                    ),
                ),
                'public' => array (
                    'get' => array (
                        'AssetPairs',
                        'AssetPairs/{id}',
                        'IsAlive',
                        'OrderBooks',
                        'OrderBooks/{AssetPairId}',
                    ),
                ),
                'private' => array (
                    'get' => array (
                        'Orders',
                        'Orders/{id}',
                        'Wallets',
                    ),
                    'post' => array (
                        'Orders/limit',
                        'Orders/market',
                        'Orders/{id}/Cancel',
                    ),
                ),
            ),
            'fees' => array (
                'trading' => array (
                    'tierBased' => false,
                    'percentage' => true,
                    'maker' => 0.0010,
                    'taker' => 0.0019,
                ),
                'funding' => array (
                    'tierBased' => false,
                    'percentage' => false,
                    'withdraw' => array (
                        'BTC' => 0.001,
                    ),
                    'deposit' => array (
                        'BTC' => 0,
                    ),
                ),
            ),
        ));
    }

    public function fetch_balance ($params = array ()) {
        $this->load_markets();
        $balances = $this->privateGetWallets ();
        $result = array ( 'info' => $balances );
        for ($i = 0; $i < count ($balances); $i++) {
            $balance = $balances[$i];
            $currency = $balance['AssetId'];
            $total = $balance['Balance'];
            $used = $balance['Reserved'];
            $free = $total - $used;
            $result[$currency] = array (
                'free' => $free,
                'used' => $used,
                'total' => $total,
            );
        }
        return $this->parse_balance($result);
    }

    public function cancel_order ($id, $symbol = null, $params = array ()) {
        return $this->privatePostOrdersIdCancel (array ( 'id' => $id ));
    }

    public function create_order ($symbol, $type, $side, $amount, $price = null, $params = array ()) {
        $this->load_markets();
        $market = $this->market ($symbol);
        $query = array (
            'AssetPairId' => $market['id'],
            'OrderAction' => $this->capitalize ($side),
            'Volume' => $amount,
        );
        if ($type === 'market') {
            $query['Asset'] = ($side === 'buy') ? $market['base'] : $market['quote'];
        } else if ($type === 'limit') {
            $query['Price'] = $price;
        }
        $method = 'privatePostOrders' . $this->capitalize ($type);
        $result = $this->$method (array_merge ($query, $params));
        return array (
            'id' => null,
            'info' => $result,
        );
    }

    public function fetch_markets () {
        $markets = $this->publicGetAssetPairs ();
        $result = array ();
        for ($i = 0; $i < count ($markets); $i++) {
            $market = $markets[$i];
            $id = $market['Id'];
            $base = $market['BaseAssetId'];
            $quote = $market['QuotingAssetId'];
            $base = $this->common_currency_code($base);
            $quote = $this->common_currency_code($quote);
            $symbol = $market['Name'];
            $precision = array (
                'amount' => $market['Accuracy'],
                'price' => $market['InvertedAccuracy'],
            );
            $result[] = array_merge ($this->fees['trading'], array (
                'id' => $id,
                'symbol' => $symbol,
                'base' => $base,
                'quote' => $quote,
                'active' => true,
                'info' => $market,
                'lot' => pow (10, -$precision['amount']),
                'precision' => $precision,
                'limits' => array (
                    'amount' => array (
                        'min' => pow (10, -$precision['amount']),
                        'max' => pow (10, $precision['amount']),
                    ),
                    'price' => array (
                        'min' => pow (10, -$precision['price']),
                        'max' => pow (10, $precision['price']),
                    ),
                ),
            ));
        }
        return $result;
    }

    public function parse_ticker ($ticker, $market = null) {
        $timestamp = $this->milliseconds ();
        $symbol = null;
        if ($market)
            $symbol = $market['symbol'];
        $ticker = $ticker['Result'];
        return array (
            'symbol' => $symbol,
            'timestamp' => $timestamp,
            'datetime' => $this->iso8601 ($timestamp),
            'high' => null,
            'low' => null,
            'bid' => floatval ($ticker['Rate']['Bid']),
            'ask' => floatval ($ticker['Rate']['Ask']),
            'vwap' => null,
            'open' => null,
            'close' => null,
            'first' => null,
            'last' => null,
            'change' => null,
            'percentage' => null,
            'average' => null,
            'baseVolume' => null,
            'quoteVolume' => null,
            'info' => $ticker,
        );
    }

    public function fetch_ticker ($symbol, $params = array ()) {
        $this->load_markets();
        $market = $this->market ($symbol);
        $ticker = $this->mobileGetAllAssetPairRatesMarket (array_merge (array (
            'market' => $market['id'],
        ), $params));
        return $this->parse_ticker($ticker, $market);
    }

    public function parse_order_status ($status) {
        if ($status === 'Pending') {
            return 'open';
        } else if ($status === 'InOrderBook') {
            return 'open';
        } else if ($status === 'Processing') {
            return 'open';
        } else if ($status === 'Matched') {
            return 'closed';
        } else if ($status === 'Cancelled') {
            return 'canceled';
        } else if ($status === 'NotEnoughFunds') {
            return 'NotEnoughFunds';
        } else if ($status === 'NoLiquidity') {
            return 'NoLiquidity';
        } else if ($status === 'UnknownAsset') {
            return 'UnknownAsset';
        } else if ($status === 'LeadToNegativeSpread') {
            return 'LeadToNegativeSpread';
        }
        return $status;
    }

    public function parse_order ($order, $market = null) {
        $status = $this->parse_order_status($order['Status']);
        $symbol = null;
        if (!$market) {
            if (is_array ($order) && array_key_exists ('AssetPairId', $order))
                if (is_array ($this->markets_by_id) && array_key_exists ($order['AssetPairId'], $this->markets_by_id))
                    $market = $this->markets_by_id[$order['AssetPairId']];
        }
        if ($market)
            $symbol = $market['symbol'];
        $timestamp = null;
        if (is_array ($order) && array_key_exists ('LastMatchTime', $order)) {
            $timestamp = $this->parse8601 ($order['LastMatchTime']);
        } else if (is_array ($order) && array_key_exists ('Registered', $order)) {
            $timestamp = $this->parse8601 ($order['Registered']);
        } else if (is_array ($order) && array_key_exists ('CreatedAt', $order)) {
            $timestamp = $this->parse8601 ($order['CreatedAt']);
        }
        $price = $this->safe_float($order, 'Price');
        $amount = $this->safe_float($order, 'Volume');
        $remaining = $this->safe_float($order, 'RemainingVolume');
        $filled = $amount - $remaining;
        $cost = $filled * $price;
        $result = array (
            'info' => $order,
            'id' => $order['Id'],
            'timestamp' => $timestamp,
            'datetime' => $this->iso8601 ($timestamp),
            'symbol' => $symbol,
            'type' => null,
            'side' => null,
            'price' => $price,
            'cost' => $cost,
            'average' => null,
            'amount' => $amount,
            'filled' => $filled,
            'remaining' => $remaining,
            'status' => $status,
            'fee' => null,
        );
        return $result;
    }

    public function fetch_order ($id, $symbol = null, $params = array ()) {
        $response = $this->privateGetOrdersId (array_merge (array (
            'id' => $id,
        ), $params));
        return $this->parse_order($response);
    }

    public function fetch_orders ($symbol = null, $since = null, $limit = null, $params = array ()) {
        $response = $this->privateGetOrders ();
        return $this->parse_orders($response, null, $since, $limit);
    }

    public function fetch_open_orders ($symbol = null, $since = null, $limit = null, $params = array ()) {
        $response = $this->privateGetOrders (array_merge (array (
            'status' => 'InOrderBook',
        ), $params));
        return $this->parse_orders($response, null, $since, $limit);
    }

    public function fetch_closed_orders ($symbol = null, $since = null, $limit = null, $params = array ()) {
        $response = $this->privateGetOrders (array_merge (array (
            'status' => 'Matched',
        ), $params));
        return $this->parse_orders($response, null, $since, $limit);
    }

    public function fetch_order_book ($symbol = null, $params = array ()) {
        $this->load_markets();
        $response = $this->publicGetOrderBooksAssetPairId (array_merge (array (
            'AssetPairId' => $this->market_id($symbol),
        ), $params));
        $orderbook = array (
            'timestamp' => null,
            'bids' => array (),
            'asks' => array (),
        );
        $timestamp = null;
        for ($i = 0; $i < count ($response); $i++) {
            $side = $response[$i];
            if ($side['IsBuy']) {
                $orderbook['bids'] = $this->array_concat($orderbook['bids'], $side['Prices']);
            } else {
                $orderbook['asks'] = $this->array_concat($orderbook['asks'], $side['Prices']);
            }
            $timestamp = $this->parse8601 ($side['Timestamp']);
            if (!$orderbook['timestamp']) {
                $orderbook['timestamp'] = $timestamp;
            } else {
                $orderbook['timestamp'] = max ($orderbook['timestamp'], $timestamp);
            }
        }
        if (!$timestamp)
            $timestamp = $this->milliseconds ();
        return $this->parse_order_book($orderbook, $orderbook['timestamp'], 'bids', 'asks', 'Price', 'Volume');
    }

    public function parse_bid_ask ($bidask, $priceKey = 0, $amountKey = 1) {
        $price = floatval ($bidask[$priceKey]);
        $amount = floatval ($bidask[$amountKey]);
        if ($amount < 0)
            $amount = -$amount;
        return array ( $price, $amount );
    }

    public function sign ($path, $api = 'public', $method = 'GET', $params = array (), $headers = null, $body = null) {
        $url = $this->urls['api'][$api] . '/' . $this->implode_params($path, $params);
        $query = $this->omit ($params, $this->extract_params($path));
        if ($api === 'public') {
            if ($query)
                $url .= '?' . $this->urlencode ($query);
        } else if ($api === 'private') {
            if ($method === 'GET')
                if ($query)
                    $url .= '?' . $this->urlencode ($query);
            $this->check_required_credentials();
            $headers = array (
                'api-key' => $this->apiKey,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            );
            if ($method === 'POST')
                if ($params)
                    $body = $this->json ($params);
        }
        return array ( 'url' => $url, 'method' => $method, 'body' => $body, 'headers' => $headers );
    }
}
