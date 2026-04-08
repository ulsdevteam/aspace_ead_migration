<?php

namespace Drupal\aspace_ead_migration;

use GuzzleHttp\Client;

/**
 * An ArchivesSpace authenticated session object.
 */
class ArchivesSpaceSession {

  /**
   * Connection Information.
   * @var array
   */
  protected $connectionInfo = [
    'base_uri' => 'https://pittapi.as.atlas-sys.com',
    'username' => 'admin',
    'password' => 'admin',
  ];

  /**
   * Session ID.
   *
   * @var string
   */
  protected $session = '';

  /**
   * {@inheritdoc}
   */
  public function __construct() {
  }

  /**
   * Create a session with connection information.
   *
   * @param string $base_uri
   *   The base URI for the ArchivesSpace API.
   * @param string $username
   *   The username to use for authentication.
   * @param string $password
   *   The password to use for authentication.
   */
  public static function withConnectionInfo($base_uri, $username, $password) {
    if (!empty($base_uri) && !preg_match("@^https?://@", $base_uri)) {
      throw new \InvalidArgumentException('Could not connect with invalid base URI: ' . $base_uri);
    }
    if (empty($username) || empty($password)) {
      throw new \InvalidArgumentException('Could not connect. Either the username or password was missing.');
    }
    $instance = new self();
    $instance->connectionInfo = [
      'base_uri' => $base_uri,
      'username' => $username,
      'password' => $password,
    ];

    return $instance;
  }

  /**
   * Either logs in or returns the current session.
   *
   * @return ArchivesSpaceSession
   *   The ArchivesSpace session object
   */
  public function getSession() {
    if (empty($this->session)) {
      $this->login();
    }
    return $this->session;
  }

  /**
   * Issues an ArchivesSpace request.
   *
   * @param string $type
   *   GET or POST request
   * @param string $path
   *   The API path to use for the request.
   * @param array $parameters
   *   Either GET query parameters or array to POST as JSON.
   * @param bool $binary
   *   Response format as binary.
   * @param bool $xml_f
   *   Expect Response content if set True.
   * @return mixed
   *   Either an array of response data OR
   *   a GuzzleHttp\Psr7\Stream if $binary is true.
   */
  public function request(string $type, string $path, array $parameters = [], $binary = FALSE, $xml_f = FALSE) {
    if (!in_array($type, ['GET', 'POST'])) {
      throw new \InvalidArgumentException('Cant\'t make an ArchivesSpace request with type: ' . $type);
    }
    if (empty($this->session)) {
      $this->login();
    }
    $client = new Client(['base_uri' => $this->connectionInfo['base_uri']]);

    $request_data = [
      'headers' => [
        'X-ArchivesSpace-Session' => $this->session,
        'content-type' => 'application/xml',
      ],
    ];

    switch ($type) {
      case 'GET':
        $request_data['query'] = $parameters;
        break;

      case 'POST':
        $request_data['json'] = $parameters;
    }
    try {
      $response = $client->request($type, $path, $request_data);

      return ($binary) ? $response->getBody() : ($xml_f ? $response->getBody()->getContents() : json_decode($response->getBody(), TRUE));
    }
    catch (RequestException $e)  {
      \Drupal::logger('aspace_ead_migration')->warning(
        'Failed to process HTTP request @type from @path',
        ['@type' => $type, '@msg' => $e->getMessage()]);
    }
  }

  /**
   * Login to ArchivesSpace.
   */
  protected function login() {
  
    $state_config = [];
  
    $baseUrl =\Drupal::config('aspace_ead_migration.settings')->get('archivesspace_base_uri');
    $userName =\Drupal::config('aspace_ead_migration.settings')->get('archivesspace_username'); 
    $passWord =\Drupal::config('aspace_ead_migration.settings')->get('archivesspace_password');
    if ( !empty($baseUrl )) {
	    $state_config['base_uri'] = $baseUrl;
	  }
    if ( !empty($userName )) {                                                                                                             
      $state_config['username'] = $userName;                                                                                          
    }        
    if ( !empty($passWord )) {                                                                                                             
      $state_config['password'] = $passWord;                                                                                          
    }         
    $this->connectionInfo = array_replace($this->connectionInfo, $state_config);

    // Setup the client.
    $client = new Client([
      'base_uri' => $this->connectionInfo['base_uri'],
    ]);

    // Form the query string and make the call.
    $login_url = '/users/' .
                 rawurlencode($this->connectionInfo['username']) .
                 '/login?password=' .
                 rawurlencode($this->connectionInfo['password']);

    $response = $client->post($login_url);

    // Return the Session ID from the response.
    $login_response = json_decode($response->getBody(), TRUE);
    $this->session = $login_response['session'];
  }

}
