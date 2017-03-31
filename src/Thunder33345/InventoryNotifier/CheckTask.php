<?php
/* Made By Thunder33345 */
namespace Thunder33345\InventoryNotifier;

use pocketmine\scheduler\PluginTask;

class CheckTask extends PluginTask
{
  private $loader;

  public function __construct(Loader $loader)
  {
    parent::__construct($loader);
    $this->loader = $loader;
  }

  public function onRun($currentTick)
  {
    $this->loader->onCheck();
  }
}