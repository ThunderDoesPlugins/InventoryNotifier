<?php
/** Created By Thunder33345 **/
namespace Thunder33345\InventoryNotifier;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\level\sound\BlazeShootSound;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;

class Loader extends PluginBase implements Listener
{
  private $skipPerm = false;
  private $default = true;
  private $notify = 5;
  private $message = "";
  private $renotify = 5;

  private $players = [];
  private $notifications = [];

  public function onLoad() { }

  public function onEnable()
  {
    if(!file_exists($this->getDataFolder()))
      @mkdir($this->getDataFolder());
    $this->saveDefaultConfig();
    $this->getServer()->getPluginManager()->registerEvents($this,$this);

    $task = new CheckTask($this);
    $repeat = (int)$this->getConfig()->get("task",200);
    if(!is_numeric($repeat)) $repeat = 200;
    $this->getServer()->getScheduler()->scheduleDelayedRepeatingTask($task,100,$repeat);

    $this->skipPerm = (bool)$this->getConfig()->get("disable-permission",false);

    $default = (bool)$this->getConfig()->get("default",true);
    if(!is_bool($default)) $default = true;
    $this->default = $default;

    $notify = (int)$this->getConfig()->get("notify",5);
    if(!is_int($notify)) $notify = 5;
    $this->notify = $notify;

    $renotify = (int)$this->getConfig()->get("renotify",5);
    if(!is_int($renotify)) $renotify = 5;
    $this->renotify = $renotify;

    $this->message = $this->replaceColour($this->getConfig()->get("message",'[Notice] &player your inventory is full!'));
  }

  public function onDisable() { }

  /*
   * @priority monitor
   * We just need to create and load temp player config
   */
  public function onJoin(PlayerJoinEvent $event) { $this->loadConfig($event->getPlayer()); }

  /*
   * @priority monitor
   * We just need to remove temp player config
   */
  public function onLeave(PlayerQuitEvent $event) { $this->unloadConfig($event->getPlayer()); }

  public function onCheck()
  {
    $allPlayers = $this->getServer()->getOnlinePlayers();
    foreach($allPlayers as $player){
      if(!$this->skipPerm AND !$player->hasPermission("inventorynotifier.use"))
        continue;
      if((bool)$this->getPlayerConfig($player) === false OR $this->hasNotified($player)) continue;
      if($this->isInventoryFull($player)) {
        $player->sendMessage(str_replace(["&player"],[$player->getName()],$this->message));
        for($i = 0; $i <= 5,$i++;){
          $player->getLevel()->addSound(new BlazeShootSound($player,$i),$player);
        }
        $this->setNotified($player);
      }
    }
  }

  public function onCommand(CommandSender $sender,Command $command,$label,array $args)
  {
    if(!isset($args[0]) OR empty($args[0])) $args[0] = "?";
    switch(strtolower($args[0])){
      case "?":
      case "help":
      case "usage":
        if(!$this->skipPerm AND !$sender->hasPermission("inventorynotifier.use")) {
          $sender->sendMessage("[InventoryNotifier] /InventoryNotifier <info|?>");
          break;
        }
        if($sender instanceof Player) {
          $sender->sendMessage("[InventoryNotifier] /InventoryNotifier <on|off|show|info|?>");
          $sender->sendMessage("[InventoryNotifier] Aliases: /invnoti");
          $sender->sendMessage("[InventoryNotifier] Current State: ".($this->getPlayerConfig($sender) ? "Enable" : "Disable"));
        }
        if($sender instanceof ConsoleCommandSender) {
          $this->getLogger()->info("/InventoryNotifier <info|?>");
          $sender->sendMessage("[InventoryNotifier] Aliases: /invnoti");
        }
        break;

      case "on":
      case "true":
      case "1":
      case "enable":
        if(!$sender instanceof Player) {
          $sender->sendMessage("This command only works with player.");
          break;
        }
        if(!$this->skipPerm AND !$sender->hasPermission("inventorynotifier.use")) {
          $sender->sendMessage("[InventoryNotifier] Insufficient Permissions.");
          break;
        }
        $this->setPlayerSetting($sender,true);
        $sender->sendMessage("[InventoryNotifier] You have now subscribed to inventory notification");
        break;

      case "off":
      case "false":
      case "0":
      case "disable":
        if(!$sender instanceof Player) {
          $sender->sendMessage("This command only works with player.");
          break;
        }
        if(!$this->skipPerm AND !$sender->hasPermission("inventorynotifier.use")) {
          $sender->sendMessage("[InventoryNotifier] Insufficient Permissions.");
          break;
        }
        $this->setPlayerSetting($sender,false);
        $sender->sendMessage("[InventoryNotifier] You have now unsubscribed to inventory notification");
        break;

      case "show":
      case "current":
      case "status":
        if(!$sender instanceof Player) {
          $sender->sendMessage("This command only works with player.");
          break;
        }
        if(!$this->skipPerm AND !$sender->hasPermission("inventorynotifier.use")) {
          $sender->sendMessage("[InventoryNotifier] Insufficient Permissions.");
          break;
        }
        $sender->sendMessage("[InventoryNotifier] Current State: ".($this->getPlayerConfig($sender) ? "Enable" : "Disable"));
        break;

      case "info":
        $sender->sendMessage("[InventoryNotifier] Made By Thunder33345");
        $sender->sendMessage("[InventoryNotifier] Default: ".($this->default ? "Enable" : "Disable"));
        $sender->sendMessage("[InventoryNotifier] Disable Permissions: ".($this->skipPerm ? "True" : "False"));
        $sender->sendMessage("[InventoryNotifier] Task Timer: Every ".(int)$this->getConfig()->get("task",200) / 20 ." seconds");
        $sender->sendMessage("[InventoryNotifier] Notify Count: ".(int)$this->getConfig()->get("notify",5));
        $sender->sendMessage("[InventoryNotifier] Notify Time: ".$this->renotify." mins");
        $sender->sendMessage("[InventoryNotifier] Warn Message: ".$this->message);
        //$sender->sendMessage("[InventoryNotifier] ");
        break;
    }
  }

  /**
   * Private API
   */
  /**
   * Replaces colour, just some cool hax inspired by PocketMine's @ priority tag detection system
   * @param string $string
   * Full message string
   *
   * @param string $trigger
   * Anything before and after * denote the trigger, *(wildcard) which would denote colour code
   * example ![*] means ![colourcode] ![BLACK]
   * @return string
   * Formatted String
   */
  private function replaceColour($string,$trigger = "![*]"): string
  {
    preg_match('/(.*)\*(.*)/',$trigger,$trim);
    preg_match_all('/'.preg_quote($trim[1]).'([A-Z a-z \_]*)'.preg_quote($trim[2]).'/',$string,$matches);
    foreach($matches[1] as $key => $colourCode){
      if(strpos($string,$matches[0][$key]) === false) continue;
      $colourCode = strtoupper($colourCode);
      if(defined(TextFormat::class."::".$colourCode)) {
        $code = constant(TextFormat::class."::".$colourCode);
        $string = str_replace($matches[0][$key],$code,$string);
      }
    }
    return $string;
  }

  /**
   * Check if player inventory is full
   * @param $player Player
   * @return bool
   */
  private function isInventoryFull(Player $player)
  {
    $inv = $player->getInventory();
    if(count($inv->getContents()) >= $inv->getSize()) return true; else return false;
  }

  /**
   * Check if player has been notified
   * @param $player Player|String
   * @return bool
   */
  private function hasNotified($player)
  {
    if($player instanceof Player) $player = $player->getName();
    $player = strtolower($player);
    $this->getLogger()->debug(print_r($this->notifications,true));
    if(isset($this->notifications[$player][1]) AND $this->notifications[$player][1] < time()) {
      $this->getLogger()->debug("Time Over Unsetting");
      unset($this->notifications[$player][1]);
      unset($this->notifications[$player][0]);
      return false;
    }
    if(isset($this->notifications[$player][0])) $notified = $this->notifications[$player][0];
    else $notified = 0;
    $this->getLogger()->debug("return: ".($notified >= $this->notify ? "true" : "false"));
    if($notified >= $this->notify) return true;
    else return false;
  }

  /**
   * set player to be notified
   * @param $player Player|String
   */
  private function setNotified($player)
  {
    if($player instanceof Player) $player = $player->getName();
    $player = strtolower($player);
    if(!isset($this->notifications[$player][1])) $this->notifications[$player][1] = time() + ($this->renotify * 60);
    if(isset($this->notifications[$player][0])) $this->notifications[$player][0]++; else $this->notifications[$player][0] = 1;
  }

  /**
   * More API
   */

  /**
   * Get config instance
   * @return Config
   */
  private function getData() { return new Config($this->getDataFolder()."settings.json",Config::JSON); }

  /**
   * Loads the setting of said player into memory
   * @param $player Player|String
   * (this should be reserved to only this plugin)
   */
  public function loadConfig($player)
  {
    if($player instanceof Player) $player = $player->getName();
    $player = strtolower($player);
    $config = $this->getData();
    $setting = $config->get($player,$this->default);
    if($setting !== $this->default) $this->players[$player] = $setting;
  }

  /**
   * Loads the setting of said player from memory
   * @param $player Player|String
   * (this should be reserved to only this plugin)
   */
  public function unloadConfig($player)
  {
    if($player instanceof Player) $player = $player->getName();
    $player = strtolower($player);
    unset($this->players[$player]);
  }

  /**
   * Gets the setting of said player
   * @param $player Player|String
   * @return bool
   */
  public function getPlayerConfig($player)
  {
    if($player instanceof Player) $player = $player->getName();
    $player = strtolower($player);
    if(isset($this->players[$player])) return $this->players[$player];
    else return $this->default;
  }

  /**
   * Toggle player's setting
   * @param $player Player|String
   */
  public function toggleSetting($player)
  {
    if($player instanceof Player) $player = $player->getName();
    $player = strtolower($player);
    $toggle = !$this->getPlayerConfig($player);
    $this->setPlayerSetting($player,$toggle);
  }

  /**
   * Set player setting to said value
   * @param $player
   * @param bool $switch
   */
  public function setPlayerSetting($player,bool $switch)
  {
    if($player instanceof Player) $player = $player->getName();
    $player = strtolower($player);
    if($switch === $this->default) {
      if(isset($this->players[$player])) unset($this->players[$player]);
      $data = $this->getData();
      $data->remove($player);
    } else {
      $this->players[$player] = $switch;
      $data = $this->getData();
      $data->set($player,$switch);
    }
    $data->save();
  }
}