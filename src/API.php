<?php

namespace Tualo\Office\FiskalyAPI;

use Tualo\Office\Basic\TualoApplication;
use Ramsey\Uuid\Uuid;
use GuzzleHttp\Client;

class API
{

    private static $ENV = null;
    private static $TSS = null;
    private static $clientID = '5059fbe8-1b3b-11ee-a0f1-0cc47a979684';

    public static function addEnvrionment(string $id, string $val)
    {
        self::$ENV[$id] = $val;
        $db = TualoApplication::get('session')->getDB();
        try {
            if (!is_null($db)) {
                $db->direct('insert into fiskaly_environments (id,val) values ({id},{val}) on duplicate key update val=values(val)', [
                    'id' => $id,
                    'val' => $val
                ]);
            }
        } catch (\Exception $e) {
        }
    }



    public static function replacer($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = self::replacer($value);
            }
            return $data;
        } else if (is_string($data)) {
            $env = self::getEnvironment();
            foreach ($env as $key => $value) {
                $data = str_replace('{{' . $key . '}}', $value, $data);
            }
            return $data;
        }
        return $data;
    }

    
    public static function query(string $url, mixed $header, mixed $data, string $method = 'POST'): mixed
    {
        $ch = curl_init(self::replacer($url));
        echo self::replacer($url);
        echo "\n\n\n";
        $header = self::replacer($header);
        print_r($header);
        echo "\n\n\n";
        if (is_array($header)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        }


        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($method == 'PUT') {
            $data = self::replacer($data);
            $payload = json_encode($data);

            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            // curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Length: ' . strlen($payload)));

            curl_setopt($ch, CURLOPT_PUT, true);
        }
        if ($method == 'POST') {
            if (is_array($data) && count($data) > 0) {
                $data = self::replacer($data);
                $payload = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            }
            curl_setopt($ch, CURLOPT_POST, true);
        }


        $result = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        echo "STATUS  $status\n\n\n";
        curl_close($ch);
        echo $result . "\n\n\n";
        $json = json_decode($result, true);
        if (isset($json['status_code']) && ($json['status_code'] != 200)) {
            throw new \Exception($json['message']);
        }
        return $json;
    }

    public static function getEnvironment(): array
    {
        if (is_null(self::$ENV)) {
            $db = TualoApplication::get('session')->getDB();
            try {
                if (!is_null($db)) {
                    $data = $db->direct('select id,val from fiskaly_environments');
                    foreach ($data as $d) {
                        self::$ENV[$d['id']] = $d['val'];
                    }
                }
            } catch (\Exception $e) {
            }
        }
        return self::$ENV;
    }

    public static function getTss(): array
    {
        if (is_null(self::$TSS)) {
            $db = TualoApplication::get('session')->getDB();
            try {
                if (!is_null($db)) {
                    $data = $db->direct('select id,val from fiskaly_tss where tss={guid}', [
                        'guid' => self::env('guid')
                    ]);
                    foreach ($data as $d) {
                        self::$TSS[$d['id']] = $d['val'];
                    }
                }
            } catch (\Exception $e) {
            }
        }
        return self::$TSS;
    }

    public static function addTss(string $id, string $val)
    {
        self::$TSS[$id] = $val;
        $db = TualoApplication::get('session')->getDB();
        try {
            if (!is_null($db)) {
                $db->direct('insert into fiskaly_tss (tss,id,val) values ({guid},{id},{val}) on duplicate key update val=values(val)', [
                    'guid' => self::env('guid'),
                    'id' => $id,
                    'val' => $val
                ]);
            }
        } catch (\Exception $e) {
            
        }
    }

    public static function tss($key)
    {
        $env = self::getTss();
        if (isset($env[$key])) {
            return $env[$key];
        }
        throw new \Exception('TSS data ' . $key . ' not found!');
    }

    public static function env($key)
    {
        $env = self::getEnvironment();
        if (isset($env[$key])) {
            return $env[$key];
        }
        throw new \Exception('Environment ' . $key . ' not found!');
    }

    public static function precheck()
    {
        $env = self::getEnvironment();
        if (!isset($env['access_token'])) {
            self::auth([
                'Content-Type:application/json'
            ]);
        }
        if (isset($env['access_token_expires_at'])) {
            if (intval($env['access_token_expires_at']) < time() - 60) {
                throw new \Exception('access_token expired!');
            }
        }
    }

    public static function changeToken()
    {
    }

    public static function auth()
    {


        $client = new Client(
            [
                'base_uri' => self::env('sign_base_url'),
                'timeout'  => 2.0,
            ]
        );
        $response = $client->post('/api/v2/auth', [
            'json' => [
                'api_key' => self::env('api_key'),
                'api_secret' => self::env('api_secret')
            ]
        ]);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);
        if (isset($result['access_token'])) {
            self::addEnvrionment('access_token', $result['access_token']);
            self::addEnvrionment('access_token_expires_at', $result['access_token_expires_at']);

            self::addEnvrionment('refresh_token', $result['refresh_token']);
            self::addEnvrionment('refresh_token_expires_at', $result['refresh_token_expires_at']);
        }
        return $result;
    }

    public static function getCashRegisters()
    {
        $client = new Client(
            [
                'base_uri' => self::env('dsfinvk_base_url'),
                'timeout'  => 2.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        $response = $client->get('/api/v1/cash_registers', [
            'json' => [
            ]
        ]);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);
        return $result;
    }


    public static function getVatDefinitions()
    {
        $client = new Client(
            [
                'base_uri' => self::env('dsfinvk_base_url'),
                'timeout'  => 2.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        $response = $client->get('/api/v1/vat_definitions', [
            'json' => [
            ]
        ]);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);
        return $result;
    }

    public static function createTSS()
    {
        self::precheck();
        if (!isset(self::$ENV['guid'])) {
            self::addEnvrionment('guid', (Uuid::uuid4())->toString());
        }


        $client = new Client(
            [
                'base_uri' => self::env('sign_base_url'),
                'timeout'  => 2.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        $response = $client->put('/api/v2/tss/' . self::env('guid'), [
            'json' => [
                'metadata' => [
                    'custom_field' => 'custom_value'
                ]
            ]
        ]);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);

        if (isset($result['certificate'])) {
            foreach ($result as $id => $val) {
                self::addTss($id, (is_array($val) ? json_encode($val) : $val));
                
            }
        }


        return $result;
    }

    public static function personalizeTSS()
    {
        self::precheck();
        if (!isset(self::$ENV['guid'])) {
            self::addEnvrionment('guid', (Uuid::uuid4())->toString());
        }


        $client = new Client(
            [
                'base_uri' => self::env('sign_base_url'),
                'timeout'  => 60.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        $response = $client->patch('/api/v2/tss/' . self::env('guid'), [
            'json' => [
                'state' => 'UNINITIALIZED'
            ]
        ]);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);
        if (isset($result['certificate'])) {
            foreach ($result as $id => $val) {
                self::addTss($id, is_array($val) ? json_encode($val) : $val);
                
            }
        }
        return $result;
    }


    public static function initializeTSS()
    {
        self::precheck();
        if (!isset(self::$ENV['guid'])) {
            self::addEnvrionment('guid', (Uuid::uuid4())->toString());
        }


        $client = new Client(
            [
                'base_uri' => self::env('sign_base_url'),
                'timeout'  => 60.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        $response = $client->patch('/api/v2/tss/' . self::env('guid'), [
            'json' => [
                'state' => 'INITIALIZED'
            ]
        ]);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);
        if (isset($result['certificate'])) {
            foreach ($result as $id => $val) {
                self::addTss($id, is_array($val) ? json_encode($val) : $val);
                
            }
        }
        return $result;
    }


    public static function getTSSInformation(string $terminal_id)
    {
        self::precheck();
        if (!isset(self::$ENV['guid'])) {
            throw new \Exception('TSS not initialized');
        }

        self::$clientID = TualoApplication::get('session')
            ->getDB()
            ->singleValue(
                'select tss_client_id from kassenterminals_client_id where  kassenterminal={kassenterminal}',
                [
                    'kassenterminal'=>$terminal_id
                ],'tss_client_id');

        $client = new Client(
            [
                'base_uri' => self::env('sign_base_url'),
                'timeout'  => 60.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        $response = $client->get('/api/v2/tss/' . self::env('guid'));
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);
        if (isset($result['certificate'])) {
            foreach ($result as $id => $val) {
                self::addTss($id, is_array($val) ? json_encode($val) : $val);
                
            }
        }

        $tss = $result;

        $response = $client->get('/api/v2/tss/' . self::env('guid').'/client/'.self::$clientID);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);
        if (isset($result['certificate'])) {
            foreach ($result as $id => $val) {
                self::addTss($id, is_array($val) ? json_encode($val) : $val);
                
            }
        }
        return [
            'tss'=>$tss,
            'client'=>$result
        ];
    }

    public static function adminPin()
    {
        self::precheck();
        if (!isset(self::$ENV['guid'])) {
            self::addEnvrionment('guid', (Uuid::uuid4())->toString());
        }


        $client = new Client(
            [
                'base_uri' => self::env('sign_base_url'),
                'timeout'  => 2.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        $response = $client->patch('/api/v2/tss/' . self::env('guid').'/admin', [
            'json' => [
                'admin_puk' => self::tss('admin_puk'),
                'new_admin_pin' => self::tss('admin_pin'),
            ]
        ]);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);


        return $result;
    }

    public static function authenticateAdmin()
    {
        self::precheck();
        if (!isset(self::$ENV['guid'])) {
            self::addEnvrionment('guid', (Uuid::uuid4())->toString());
        }


        $client = new Client(
            [
                'base_uri' => self::env('sign_base_url'),
                'timeout'  => 2.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        $response = $client->post('/api/v2/tss/' . self::env('guid').'/admin/auth', [
            'json' => [
                'admin_pin' => self::tss('admin_pin')
            ]
        ]);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);


        return $result;
    }


    public static function logoutAdmin()
    {
        self::precheck();
        if (!isset(self::$ENV['guid'])) {
            self::addEnvrionment('guid', (Uuid::uuid4())->toString());
        }


        $client = new Client(
            [
                'base_uri' => self::env('sign_base_url'),
                'timeout'  => 2.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        $response = $client->post('/api/v2/tss/' . self::env('guid').'/admin/logout', [
            'json' => [
                'none' => 'none'
            ]
        ]);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);


        return $result;
    }


    public static function createClient($terminal_id)
    {
        self::precheck();
        if (!isset(self::$ENV['guid'])) {
            self::addEnvrionment('guid', (Uuid::uuid4())->toString());
        }

        self::$clientID = TualoApplication::get('session')
        ->getDB()
        ->singleValue(
            'select tss_client_id from kassenterminals_client_id where  kassenterminal={kassenterminal}',
            [
                'kassenterminal'=>$terminal_id
            ],'tss_client_id');
            

        $client = new Client(
            [
                'base_uri' => self::env('sign_base_url'),
                'timeout'  => 2.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        $response = $client->put('/api/v2/tss/' . self::env('guid').'/client/'.self::$clientID, [
            'json' => [
                'serial_number' => self::$clientID,
                'metadata' => [
                    'custom_field' => 'custom_value'
                ]
            ]
        ]);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);


        return $result;
    }


    public static function transaction(string $terminal_id, array $rates,string $receipt_type='TRAINING' )
    {
        self::precheck();
        if (!isset(self::$ENV['guid'])) {
            self::addEnvrionment('guid', (Uuid::uuid4())->toString());
        }

        self::$clientID = TualoApplication::get('session')
        ->getDB()
        ->singleValue(
            'select tss_client_id from kassenterminals_client_id where  kassenterminal={kassenterminal}',
            [
                'kassenterminal'=>$terminal_id
            ],'tss_client_id');

        $client = new Client(
            [
                'base_uri' => self::env('sign_base_url'),
                'timeout'  => 2.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        $transactionID = (Uuid::uuid4())->toString();
        $response = $client->put('/api/v2/tss/' . self::env('guid').'/tx/'.$transactionID.'?tx_revision=1', [
            'json' => [
                'state' => 'ACTIVE',
                'client_id' => self::$clientID
            ]
        ]);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $start_result = json_decode($response->getBody()->getContents(), true);



        $response = $client->put('/api/v2/tss/' . self::env('guid').'/tx/'.$transactionID.'?tx_revision=2', [
            'json' => [
                'state' => 'FINISHED',
                'client_id' => self::$clientID,
                'schema' => [
                    'standard_v1' => [
                        'receipt' => [
                            'receipt_type' => $receipt_type,
                            'amounts_per_vat_rate' => $rates/*,
                            'amounts_per_payment_type' => [
                                [
                                    'payment_type' => $payment_type,
                                    'amount' => number_format($normal_amount,2,'.','')
                                ]
                            ]*/
                        ]
                    ]
                ]
            ]
        ]);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $finish_result = json_decode($response->getBody()->getContents(), true);

        return $finish_result;
    }
}