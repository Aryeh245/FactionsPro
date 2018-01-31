<?php
namespace FactionsPro;

use pocketmine\plugin\PluginBase;
use pocketmine\command\{Command, CommandSender};
use pocketmine\event\Listener;
use pocketmine\event\block\{BlockPlaceEvent, BlockBreakEvent};
use pocketmine\Player;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\tile\MobSpawner;
use pocketmine\utils\{TextFormat as TF, Config};
use pocketmine\scheduler\PluginTask;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\{PlayerMoveEvent, PlayerDeathEvent, PlayerChatEvent, PlayerInteractEvent};
use pocketmine\block\Block;

class FactionListener implements Listener{
	
	public $plugin;
	
	public function __construct(FactionMain $pg){
		$this->plugin = $pg;
	}
	
	public function factionChat(PlayerChatEvent $PCE){
		
		$player = $PCE->getPlayer()->getName();
		//MOTD Check
		//TODO Use arrays instead of database for faster chatting?
		
		if($this->plugin->motdWaiting($player)){
			if(time() - $this->plugin->getMOTDTime($player) > 30){
				$PCE->getPlayer()->sendMessage($this->plugin->formatMessage("Timed out. Please use /f desc again."));
				$this->plugin->db->query("DELETE FROM motdrcv WHERE player='$player';");
				$PCE->setCancelled(true);
				return true;
			} else {
				$motd = $PCE->getMessage();
				$faction = $this->plugin->getPlayerFaction($player);
				$this->plugin->setMOTD($faction, $player, $motd);
				$PCE->setCancelled(true);
				$PCE->getPlayer()->sendMessage($this->plugin->formatMessage("Successfully updated the faction description! Type /f info.", true));
			}
			return true;
		}
		if(isset($this->plugin->factionChatActive[$player])){
			if($this->plugin->factionChatActive[$player]){
				$msg = $PCE->getMessage();
				$faction = $this->plugin->getPlayerFaction($player);
				$db = $this->plugin->db->query("SELECT * FROM master WHERE faction='$faction'");
				foreach($this->plugin->getServer()->getOnlinePlayers() as $fP){
					if($this->plugin->getPlayerFaction($fP->getName()) == $faction){
						if($this->plugin->getServer()->getPlayer($fP->getName())){
							$PCE->setCancelled(true);
							$this->plugin->getServer()->getPlayer($fP->getName())->sendMessage(TF::DARK_GREEN."[$faction]".TF::BLUE." $player: ".TF::AQUA. $msg);
						}
					}
				}
			}
		}
		if(isset($this->plugin->allyChatActive[$player])){
			if($this->plugin->allyChatActive[$player]){
				$msg = $PCE->getMessage();
				$faction = $this->plugin->getPlayerFaction($player);
				$db = $this->plugin->db->query("SELECT * FROM master WHERE faction='$faction'");
				foreach($this->plugin->getServer()->getOnlinePlayers() as $fP){
					if($this->plugin->areAllies($this->plugin->getPlayerFaction($fP->getName()), $faction)){
						if($this->plugin->getServer()->getPlayer($fP->getName())){
							$PCE->setCancelled(true);
							$this->plugin->getServer()->getPlayer($fP->getName())->sendMessage(TF::DARK_GREEN."[$faction]".TF::BLUE." $player: ".TF::AQUA. $msg);
							$PCE->getPlayer()->sendMessage(TF::DARK_GREEN."[$faction]".TF::BLUE." $player: ".TF::AQUA. $msg);
						}
					}
				}
			}
		}
	}
	
	public function factionPVP(EntityDamageEvent $factionDamage){
		if($factionDamage instanceof EntityDamageByEntityEvent){
			if(!($factionDamage->getEntity() instanceof Player) || !($factionDamage->getDamager() instanceof Player)){
				return true;
			}
			if(($this->plugin->isInFaction($factionDamage->getEntity()->getPlayer()->getName()) == false) || ($this->plugin->isInFaction($factionDamage->getDamager()->getPlayer()->getName()) == false)){
				return true;
			}
			if(($factionDamage->getEntity() instanceof Player) and ($factionDamage->getDamager() instanceof Player)){
				$player1 = $factionDamage->getEntity()->getPlayer()->getName();
				$player2 = $factionDamage->getDamager()->getPlayer()->getName();
                $f1 = $this->plugin->getPlayerFaction($player1);
                $f2 = $this->plugin->getPlayerFaction($player2);
				if($this->plugin->sameFaction($player1, $player2) == true or $this->plugin->areAllies($f1,$f2)){
					$factionDamage->setCancelled(true);
				}
			}
		}
	}

	public function onInteract(PlayerInteractEvent $e){
		if($this->plugin->isInPlot($e->getPlayer())){
			if(!$this->plugin->inOwnPlot($e->getPlayer())){
				if($e->getPlayer()->isCreative()){
					$e->getPlayer()->sendMessage($this->plugin->formatMessage("Raiding environment detected. Switching to survival mode."));
					$p->setGamemode(0);
					$e->setCancelled();
				}
				if($this->plugin->essentialsPE->isGod($e->getPlayer())){
					$e->getPlayer()->sendMessage($this->plugin->formatMessage("Raiding environment detected. Disabling god mode."));
					$e->setCancelled();
				}
			}
		}
	}

	public function factionBlockBreakProtect(BlockBreakEvent $event){
		if($this->plugin->isInPlot($event->getPlayer())){
			if($this->plugin->inOwnPlot($event->getPlayer())){
				return true;
			}else{
				$event->setCancelled(true);
				$event->getPlayer()->sendMessage($this->plugin->formatMessage("You cannot break blocks here. This is already a property of a faction. Type /f plotinfo for details."));
				return true;
			}
		}
	}
	
	public function factionBlockPlaceProtect(BlockPlaceEvent $event){
		if($this->plugin->isInPlot($event->getPlayer())){
			if($this->plugin->inOwnPlot($event->getPlayer())){
				return true;
			} else {
				$event->setCancelled(true);
				$event->getPlayer()->sendMessage($this->plugin->formatMessage("You cannot place blocks here. This is already a property of a faction. Type /f plotinfo for details."));
				return true;
			}
		}
	}

	public function onKill(PlayerDeathEvent $event){
		$ent = $event->getEntity();
		$cause = $event->getEntity()->getLastDamageCause();
		if($cause instanceof EntityDamageByEntityEvent){
			$killer = $cause->getDamager();
			if($killer instanceof Player){
				$p = $killer->getPlayer()->getName();
				if($this->plugin->isInFaction($p)){
					$f = $this->plugin->getPlayerFaction($p);
					$e = $this->plugin->prefs->get("PowerGainedPerKillingAnEnemy");
					if($ent instanceof Player){
						if($this->plugin->isInFaction($ent->getPlayer()->getName())){
							$this->plugin->addFactionPower($f,$e);
						}else{
							$this->plugin->addFactionPower($f,$e/2);
						}
					}
				}
			}
			if($ent instanceof Player){
				$e = $ent->getPlayer()->getName();
				if($this->plugin->isInFaction($e)){
					$f = $this->plugin->getPlayerFaction($e);
					$e = $this->plugin->prefs->get("PowerGainedPerKillingAnEnemy");
					if($ent->getLastDamageCause()->getDamager() instanceof Player){
						if($this->plugin->isInFaction($ent->getLastDamageCause()->getDamager()->getPlayer()->getName())){ 
							$this->plugin->subtractFactionPower($f,$e*2);
						}else{
							$this->plugin->subtractFactionPower($f,$e);
						}
					}
				}
			}
		}
	}

	/*public function onBlockBreak(BlockBreakEvent $event){
		if($event->isCancelled()) return;
		$player = $event->getPlayer();
		if(!$this->plugin->isInFaction($player->getName())) return;
		$block = $event->getBlock();
		if($block->getId() === Block::MONSTER_SPAWNER){
			$fHere = $this->plugin->factionFromPoint($block->x, $block->y);
			$playerF = $this->plugin->getPlayerFaction($player->getName());
			if($fHere !== $playerF and !$player->isOp()){ $event->setCancelled(true); return; };
		}
	}*/
}
