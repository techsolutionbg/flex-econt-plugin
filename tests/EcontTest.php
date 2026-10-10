<?php
declare(strict_types=1);
namespace Flex\Econt\Tests;
use Flex\Configuration\ConfigurationRepository;
use Flex\Database\DatabaseManager;
use Flex\Econt\{EcontSettings,EcontClient,ShippingService,EcontException};
use GuzzleHttp\{Client,HandlerStack};
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
foreach (['CommerceException','CatalogStore','OrderStore'] as $name) require_once __DIR__.'/../../commerce/src/'.$name.'.php';
foreach (['EcontException','EcontSettings','EcontClient','ShippingService'] as $name) require_once __DIR__.'/../src/'.$name.'.php';
final class EcontTest extends TestCase {
 private DatabaseManager $db;
 private EcontSettings $settings;
 protected function setUp(): void {
  $this->db=new DatabaseManager(new ConfigurationRepository(['database'=>['default'=>'sqlite','connections'=>['sqlite'=>['driver'=>'sqlite','database'=>':memory:','prefix'=>'']]]]));
  $this->db->connection(); (new \Flex\Commerce\CatalogStore())->install(); (new \Flex\Commerce\OrderStore())->install();
  $this->settings=new EcontSettings(); $this->settings->install();
 }
 protected function tearDown(): void {$this->db->disconnect();}
 private function client(array $responses): EcontClient {return new EcontClient($this->settings,new Client(['handler'=>HandlerStack::create(new MockHandler($responses))]));}
 private function configure(string $mode='test'): void {
  $this->settings->save(['enabled'=>true,'mode'=>$mode,'test_username'=>'test','test_password'=>'test','live_username'=>'live','live_password'=>'secret','sender_name'=>'Shop','sender_phone'=>'0888123456','sender_office_code'=>'1000']);
 }
 public function testInstallAndReadPreserveDisabledStateAndCommerceSettings(): void {
  self::assertFalse($this->settings->all()['enabled']); self::assertSame('', $this->settings->all()['test_username']);
  self::assertSame('fixed',(new \Flex\Commerce\CatalogStore())->settings()['shipping_mode']);
  $this->settings->ensureDemoDefaults();$this->settings->install();self::assertFalse($this->settings->all()['enabled']);
 }
 public function testPasswordsAreMaskedAndPreserved(): void {
  $this->configure(); self::assertSame('', $this->settings->publicSettings()['live_password']);
  $this->settings->save(['live_password'=>'']);self::assertSame('secret',$this->settings->all()['live_password']);
 }
 public function testCorrectModeIsOutsideLabelAndOfficeFieldsAreDistinct(): void {
  $this->configure(); $captured=[];
  $http=new Client(['handler'=>function($request,$options)use(&$captured){$captured=json_decode((string)$request->getBody(),true);self::assertFalse($options['allow_redirects']);return \GuzzleHttp\Promise\Create::promiseFor(new Response(200,[], '{"label":{"totalPrice":5,"currency":"EUR"}}'));}]);
  $client=new EcontClient($this->settings,$http);$service=new ShippingService($this->settings,$client);
  $label=$service->buildLabel('econt_office',['office_code'=>'2000'],['currency'=>'EUR','total_minor'=>1250],$this->settings->all());
  self::assertSame('1000',$label['senderOfficeCode']);self::assertSame('2000',$label['receiverOfficeCode']);self::assertSame('pack',$label['shipmentType']);self::assertSame(12.5,$label['services']['cdAmount']);
  $client->calculate($label);self::assertSame('calculate',$captured['mode']);self::assertArrayNotHasKey('mode',$captured['label']);
 }
 public function testHttpErrorsCannotBecomeSuccessfulQuotes(): void {
  $this->configure();$this->expectException(EcontException::class);$this->client([new Response(401,[], '{}')])->calculate([]);
 }
 public function testLiveFailureBlocksCheckout(): void {
  $this->configure('live');$service=new ShippingService($this->settings,$this->client([new Response(500,[], '{}')]));
  $this->expectException(\Flex\Commerce\CommerceException::class);
  $service->adjustQuote(['shipping'=>'econt_office','currency'=>'EUR','subtotal_minor'=>1000],['settings'=>['shipping_mode'=>'econt'],'selection'=>['shipping_meta'=>['office_code'=>'2000']]]);
 }
 public function testLiveCurrencyMismatchBlocksCheckout(): void {
  $this->configure('live');$service=new ShippingService($this->settings,$this->client([new Response(200,[], '{"label":{"totalPrice":5,"currency":"BGN"}}')]));
  $this->expectException(\Flex\Commerce\CommerceException::class);
  $service->adjustQuote(['shipping'=>'econt_office','currency'=>'EUR','subtotal_minor'=>1000],['settings'=>['shipping_mode'=>'econt'],'selection'=>['shipping_meta'=>['office_code'=>'2000']]]);
 }
 public function testTestFallbackAndMatchingLivePrice(): void {
  $this->configure();$service=new ShippingService($this->settings,$this->client([new Response(500,[], '{}')]));
  $quote=$service->adjustQuote(['shipping'=>'econt_office','currency'=>'EUR','subtotal_minor'=>1000],['settings'=>['shipping_mode'=>'econt'],'selection'=>['shipping_meta'=>['office_code'=>'2000']]]);
  self::assertSame('demo',$quote['shipping_meta']['price_source']);self::assertGreaterThan(0,$quote['shipping_minor']);
  $this->configure('live');$service=new ShippingService($this->settings,$this->client([new Response(200,[], '{"label":{"totalPrice":5,"currency":"EUR"}}')]));
  $quote=$service->adjustQuote(['shipping'=>'econt_office','currency'=>'EUR','subtotal_minor'=>1000],['settings'=>['shipping_mode'=>'econt'],'selection'=>['shipping_meta'=>['office_code'=>'2000']]]);
  self::assertSame(500,$quote['shipping_minor']);self::assertSame('econt',$quote['shipping_meta']['price_source']);
 }
 public function testLiveIncompleteDestinationCannotBeSubmitted(): void {
  $this->configure('live');$service=new ShippingService($this->settings,$this->client([]));
  $quote=$service->adjustQuote(['shipping'=>'econt_office','currency'=>'EUR','subtotal_minor'=>1000],['settings'=>['shipping_mode'=>'econt']]);
  self::assertFalse($quote['shipping_meta']['calculated']);
  $this->expectException(\Flex\Commerce\CommerceException::class);
  $service->validateCustomer([],['quote'=>$quote,'method'=>['id'=>'econt_office']]);
 }
 public function testFreeShippingThresholdAndZeroApiPrice(): void {
  $this->configure('live');$service=new ShippingService($this->settings,$this->client([new Response(200,[], '{"label":{"totalPrice":5,"currency":"EUR"}}')]));
  $quote=$service->adjustQuote(['shipping'=>'econt_office','currency'=>'EUR','subtotal_minor'=>1000],['settings'=>['shipping_mode'=>'econt','free_shipping_minor'=>1000],'selection'=>['shipping_meta'=>['office_code'=>'2000']]]);
  self::assertSame(0,$quote['shipping_minor']);self::assertSame(0,$service->extractPublicAmountMinor(['label'=>['totalPrice'=>0]]));
 }
}
