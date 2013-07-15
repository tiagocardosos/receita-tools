<?php
namespace Receita;

use Guzzle\Http\Client;
use Guzzle\Plugin\Cookie\CookiePlugin;
use Guzzle\Plugin\Cookie\CookieJar\ArrayCookieJar;

class CNPJClient {

  // constants
  const receitaURL = 'http://www.receita.fazenda.gov.br/pessoajuridica/cnpj/cnpjreva/';

  // attributes
  private $client;
  private $verbose;

  public function __construct($cnpj, $verbose=false)
  {
    $this->cnpj    = $cnpj;
    $this->verbose = $verbose;
    $this->client  = new Client(self::receitaURL,array('redirect.disable' => true));

    // add the cookie plugin (so cookies are kept beetwen requests)
    $cookiePlugin = new CookiePlugin(new ArrayCookieJar());
    $this->client->addSubscriber($cookiePlugin);
  }

  private function fix_captcha($text)
  {
    $text = strtoupper($text);
    $n = strlen($text);
    $res = '';
    for($i=0;$i<$n;$i++){
      if($text[$i]>='0' and $text[$i]<='9')
        $res .= $text[$i];
      if($text[$i]>='A' and $text[$i]<='Z')
        $res .= $text[$i];
    }
    return $res;
  }

  private function get_location($headers)
  {
    $headers = explode("\n",$headers);
    for($i=0;$i<count($headers);$i++){
      $header = explode(':',$headers[$i]);
      if($header[0]=='Location')
        return trim($header[1]);
    }
    return false;
  }

  public function run()
  {
    // get first two pages
    $response = $this->client->get('cnpjreva_solicitacao.asp')->send();
    if($response->getStatusCode()!=200) return false;

    $response = $this->client->get('cnpjreva_solicitacao2.asp')->send();
    if($response->getStatusCode()!=200) return false;

    // parse the response data
    $html = $response->getBody()->__toString();

    // create DOM document (ignore errors)
    $document = new DOMDocument();
    @$document->loadHTML($html);

    // find the image with id imgcaptcha
    $xpath = new DOMXPath($document);
    $captcha = $xpath->query("//img[@id='imgcaptcha']");

    // get the captcha URL
    $captchaURL = $captcha->item(0)->attributes->getNamedItem('src')->value;

    // download and save the captcha
    $response = $this->client->get($captchaURL)->send();
    if($response->getStatusCode()!=200) return false;

    $img = $response->getBody()->__toString();
    $file = fopen('captcha.gif','w');
    fwrite($file,$img);
    fclose($file);

    // find the form viewstate input
    $viewstate = $xpath->query("//input[@id='viewstate']");
    $viewstate = $viewstate->item(0)->attributes->getNamedItem('value')->value;

    // filter
    system('python filter.py captcha.gif');
    system('tesseract filtered.gif out');

    $captcha_text = trim(file_get_contents('out.txt'));
    $captcha_text = $this->fix_captcha($captcha_text);

    if($this->verbose)
      echo "Trying captcha: ".$captcha_text."\n";

    // post data (do not redirect)
    $response = $this->client->post('valida.asp', null, array(
      'origem' => 'comprovante',
      'viewstate' => $viewstate,
      'cnpj' => $this->cnpj,
      'captcha' => $captcha_text,
      'captchaAudio' => '',
      'submit1' => 'Consultar',
      'search_type' => 'cnpj'
    ))->send();
    if($response->getStatusCode()!=302) return false;

    // get location (if captcha ok or not)
    // and check for wrong captcha
    $location = $this->get_location($response->getRawHeaders());
    if(!$location or !strpos($location,"Vstatus.asp")) return false;

    // its valid captcha, but we should check if the cnpj is valid
    $response = $this->client->get($location)->send();
    if($response->getStatusCode()!=302) return false;
    $location = $this->get_location($response->getRawHeaders());
    if(strpos($location,'Erro')>0) return 'bad';

    $response = $this->client->get('Cnpjreva_Campos.asp')->send();
    if($response->getStatusCode()!=302) return false;

    $response = $this->client->get('Cnpjreva_Comprovante.asp')->send();
    if($response->getStatusCode()!=200) return false;

    return $response->getBody()->__toString();
  }

}
?>