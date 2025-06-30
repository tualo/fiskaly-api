<?php

namespace Tualo\Office\FiskalyAPI;

use Garden\Cli\Cli;
use Tualo\Office\Basic\TualoApplication;
use Ramsey\Uuid\Uuid;
use GuzzleHttp\Client;

class API
{

    private static $_db = null;
    private static $ENV = null;
    private static $TSS = null;
    private static $type = 'test';

    private static $clientID = '5059fbe8-1b3b-11ee-a0f1-0cc47a979684';

    public static function resetEnvrionment()
    {

        self::$_db = null;
        self::$ENV = null;
        self::$TSS = null;
        self::$type = 'test';
    }

    public static function setLive($yes = true)
    {
        if ($yes) {
            self::$type = 'live';
        } else {
            self::$type = 'test';
        }
    }

    public static function db($db = null)
    {
        if (!is_null($db) && is_null(self::$_db)) {
            self::$_db = $db;
        }
        if (is_null(self::$_db)) {
            self::$_db = TualoApplication::get('session')->getDB();
        }

        return self::$_db;
    }

    public static function addEnvrionment(string $id, string $val)
    {
        self::$ENV[$id] = $val;
        $db = self::db();
        try {
            if (!is_null($db)) {
                $db->direct('insert into fiskaly_environments (id,val,type) values ({id},{val},{type}) 
                    on duplicate key update val=values(val)', [
                    'id' => $id,
                    'val' => $val,
                    'type' => self::$type
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



    public static function getEnvironment(): array
    {
        if (is_null(self::$ENV)) {
            $db = self::db();
            try {
                if (!is_null($db)) {
                    $data = $db->direct('select id,val from fiskaly_environments where type={type}', [
                        'type' => self::$type
                    ]);
                    foreach ($data as $d) {
                        self::$ENV[$d['id']] = $d['val'];
                    }
                } else {
                    echo 'Error ';
                }
            } catch (\Exception $e) {
                echo 'Error fetching fiskaly environments: ' . $e->getMessage() . PHP_EOL;
            }
        }
        if (is_null(self::$ENV)) {
            self::$ENV = [];
        }
        return self::$ENV;
    }

    public static function getTss(): array
    {
        if (is_null(self::$TSS)) {
            $db = self::db();
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
        if (is_null(self::$TSS)) {
            self::$TSS = [];
        }
        return self::$TSS;
    }

    public static function addTss(string $id, string $val)
    {
        if (is_null(self::$TSS)) {
            self::$TSS = self::getTss();
        }
        self::$TSS[$id] = $val;
        $db = self::db();
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
        throw new \Exception('TSS data ' . $key . ' not found! ' . self::env('guid'));
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

    public static function changeToken() {}

    public static function auth()
    {
        if (self::isExpired(3000)) {


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
    }


    public static function clients()
    {
        $client = new Client(
            [
                'base_uri' => self::env('sign_base_url'),
                'timeout'  => 2.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        $response = $client->get('/api/v2/tss/' . self::env('guid') . '/client', []);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);
        return $result;
    }

    public static function clientTransactions(string $client_id)
    {
        $client = new Client(
            [
                'base_uri' => self::env('sign_base_url'),
                'timeout'  => 2.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        $response = $client->get('/api/v2/tss/' . self::env('guid') . '/client/' . $client_id . '/tx', []);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);
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
            'json' => []
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
            'json' => []
        ]);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $result = json_decode($response->getBody()->getContents(), true);
        return $result;
    }

    public static function createTSS(string $system = 'tualo')
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
                    'system' => $system
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

    public static function isExpired(int $seconds = 60): bool
    {
        $v = self::db()->singleValue(
            '
            select  
                count(*) x 
            from 
                fiskaly_environments 
            where 
                    id={id} 
                and type={type}
                and (val - {seconds}) < UNIX_TIMESTAMP() 
            ',
            [
                'type' => self::$type,
                'id' => 'access_token_expires_at',
                'seconds' => $seconds
            ],
            'x'
        );

        return $v > 0;
    }

    public static function getTSSInformation(string $terminal_id)
    {
        self::precheck();
        if (!isset(self::$ENV['guid'])) {
            throw new \Exception('TSS not initialized');
        }

        self::$clientID = self::db()
            ->singleValue(
                'select tss_client_id from kassenterminals_client_id where  kassenterminal={kassenterminal}',
                [
                    'kassenterminal' => $terminal_id
                ],
                'tss_client_id'
            );

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

        $response = $client->get('/api/v2/tss/' . self::env('guid') . '/client/' . self::$clientID);
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
            'tss' => $tss,
            'client' => $result
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

        $response = $client->patch('/api/v2/tss/' . self::env('guid') . '/admin', [
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
        $response = $client->post('/api/v2/tss/' . self::env('guid') . '/admin/auth', [
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
        $response = $client->post('/api/v2/tss/' . self::env('guid') . '/admin/logout', [
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
                    'kassenterminal' => $terminal_id
                ],
                'tss_client_id'
            );


        $client = new Client(
            [
                'base_uri' => self::env('sign_base_url'),
                'timeout'  => 2.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        $response = $client->put('/api/v2/tss/' . self::env('guid') . '/client/' . self::$clientID, [
            'json' => [
                'serial_number' => self::$clientID,
                'metadata' => [
                    'terminal_id' => $terminal_id
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


    public static function transaction(string $terminal_id, array $rates, string $receipt_type = 'TRAINING')
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
                    'kassenterminal' => $terminal_id
                ],
                'tss_client_id'
            );

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
        $response = $client->put('/api/v2/tss/' . self::env('guid') . '/tx/' . $transactionID . '?tx_revision=1', [
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



        $response = $client->put('/api/v2/tss/' . self::env('guid') . '/tx/' . $transactionID . '?tx_revision=2', [
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

        return [$start_result, $finish_result];
    }


    public static function getClient(string $terminal_id): Client
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
                    'kassenterminal' => $terminal_id
                ],
                'tss_client_id'
            );

        $client = new Client(
            [
                'base_uri' => self::env('sign_base_url'),
                'timeout'  => 2.0,
                'headers' => [
                    'Authorization' => 'Bearer ' . self::env('access_token')
                ]
            ]
        );
        return $client;
    }


    public static function rawTransaction(string $terminal_id, string $transactionID, string $processType, string $processData, string $state = 'FINISHED', int $revision = 1)
    {
        if (!in_array($state, ['ACTIVE', 'CANCELLED', 'FINISHED'])) {
            throw new \Exception('Invalid state: ' . $state);
        }

        $client = self::getClient($terminal_id);

        $response = $client->put('/api/v2/tss/' . self::env('guid') . '/tx/' . $transactionID . '?tx_revision=' . $revision, [
            'json' => [
                'state' => $state,
                'client_id' => self::$clientID,
                'schema' => [
                    'raw' => [
                        'process_type' => $processType,
                        'process_data' => $processData
                    ]
                ]
            ]
        ]);
        $code = $response->getStatusCode(); // 200
        $reason = $response->getReasonPhrase(); // OK

        if ($code != 200) {
            throw new \Exception($reason);
        }
        $_result = json_decode($response->getBody()->getContents(), true);

        return $_result;
    }
}
