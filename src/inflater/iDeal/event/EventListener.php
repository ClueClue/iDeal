<?php

namespace inflater\iDeal\event;

use pocketmine\plugin\PluginBase; //used
use pocketmine\event\Listener; //used
use pocketmine\utils\Utils;
use pocketmine\utils\TextFormat;
use pocketmine\utils\Config;
use pocketmine\item\Item;
use pocketmine\entity\Entity;
use pocketmine\Server;
use pocketmine\command\PluginCommand;
use inflater\iDeal\iDeal;

class EventListener implements Listener {
	private $plugin;

	public function __construct(iDeal $plugin) {
		$this->plugin = $plugin;
	}

	public function createNameTag(array $players, $pos, $text = '') {
		foreach($players as $player) {
			$playerN = strtolower($player->getName());
			if(isset($this->plugin->packet['showedEntity'][$playerN][$pos])) { $this->removeEntity([$player], $pos); }
			$this->plugin->packet['showedEntity'][$playerN][$pos] = Entity::$entityCount++;
			$this->plugin->packet["AddEntity"]->eid = $this->plugin->packet['showedEntity'][$playerN][$pos];
			$this->plugin->packet["AddEntity"]->metadata[Entity::DATA_NAMETAG] = [Entity::DATA_TYPE_STRING, $text];
			$this->plugin->packet["AddEntity"]->x = explode('.', $pos)[0] + 0.5;
			$this->plugin->packet["AddEntity"]->y = explode('.', $pos)[1];
			$this->plugin->packet["AddEntity"]->z = explode('.', $pos)[2] + 0.5;
			$player->directDataPacket($this->plugin->packet["AddEntity"]);
		}
	}

	public function addItemEntity($players, $position, $item) {
		if(!is_array($players)) { if(strtolower($players) === 'all') { $players = $this->plugin->getServer()->getInstance()->getOnlinePlayers(); } }
		foreach($players as $player) {
			if($player->getLevel()->getName() !== $position->getLevel()->getName()) { continue; }
			$playerN = strtolower($player->getName());
			$pos = $position->getX() . '.' . $position->getY() . '.' . $position->getZ();
			if(isset($this->plugin->ItemPacket[$playerN][$pos])) { continue; }

			if(!$item instanceof Item) { $item = isset(explode(':', $item)[1]) ? Item::get((int) explode(':', $item)[0], (int) explode(':', $item)[1]) : Item::get((int) explode(':', $item)[0]); }
			$this->plugin->ItemPacket[$playerN][$pos] = Entity::$entityCount++;
			$this->plugin->packet["AddItemEntity"]->eid = $this->plugin->ItemPacket[$playerN][$pos];
			$this->plugin->packet["AddItemEntity"]->item = $item;
			$this->plugin->packet["AddItemEntity"]->x = $position->getX() + 0.5;
			$this->plugin->packet["AddItemEntity"]->y = $position->getY();
			$this->plugin->packet["AddItemEntity"]->z = $position->getZ() + 0.5;
			$player->directDataPacket($this->plugin->packet["AddItemEntity"]);
		}
		return true;
	}

	public function removeEntity(array $players, $pos) {
		foreach($players as $player) {
			if(isset($this->plugin->packet['showedEntity'][strtolower($player->getName())][$pos])) {
				$this->plugin->packet["RemoveEntity"]->eid = $this->plugin->packet['showedEntity'][strtolower($player->getName())][$pos];
				$player->directDataPacket($this->plugin->packet["RemoveEntity"]);
				unset($this->plugin->packet['showedEntity'][strtolower($player->getName())][$pos]);
			}
		}
	}

	public function removeItemEntity($players, $pos) {
		if(!is_array($players)) { if(strtolower($players) === 'all') { $players = $this->plugin->getServer()->getInstance()->getOnlinePlayers(); } }
		foreach($players as $player) {
			if(isset($this->plugin->ItemPacket[strtolower($player->getName())][$pos])) {
				$this->plugin->packet["RemoveEntity"]->eid = $this->plugin->ItemPacket[strtolower($player->getName())][$pos];
				$player->directDataPacket($this->plugin->packet["RemoveEntity"]);
				unset($this->plugin->ItemPacket[strtolower($player->getName())][$pos]);
			}
		}
	}

	public function InitYML() {
		$this->plugin->message = (new Config($this->plugin->getDataFolder()."message.yml", Config::YAML, array(
			"ideal-default-mark" => "[ iDeal ]",
			"safedeal-default-mark" => "[ 안전거래 ]",
			"safedeal-command-usage" => "/거래 <요청/상태/수락/거절/취소/수신>",
			"safedeal-request-usage" => "/거래 요청 <대상닉네임> <아이템개수> <총가격>",
			"safedeal-request-description" => "들고있는 아이템으로 거래(판매)를 요청합니다.",
			"safedeal-request-fail-0" => "이미 진행중인 거래가 있습니다 ! ( /거래 취소 로 거래취소 가능 )",
			"safedeal-request-fail-1" => "본인에게 거래를 요청할 수 없습니다.",
			"safedeal-request-fail-2" => "존재하지 않는 플레이어이거나 오프라인 유저입니다.",
			"safedeal-request-fail-3" => "거래요청을 차단중인 유저입니다.",
			"safedeal-request-fail-4" => "이미 거래중인 유저입니다.",
			"safedeal-request-fail-5" => "거래하실 아이템을 들어주세요 !",
			"safedeal-request-fail-6" => "거래가 불가능한 아이템입니다.",
			"safedeal-request-fail-7" => "소유하신 아이템 개수가 부족합니다. ( 소유중인 개수 : {%0}개 )",
			"safedeal-request-fail-8" => "가격은 0{%Monetary-Unit} 이상으로 정해주셔야합니다.",
			"safedeal-request-0" => "{%0}님께 {%1}아이템 {%2}개를 {%3}{%Monetary-Unit}(으)로 거래를 요청하였습니다.",
			"safedeal-request-1" => "{%0}님이 {%1}아이템 {%2}개를 {%3}{%Monetary-Unit}(으)로 당신에게 판매하시길원합니다.",
			"safedeal-request-2" => "/거래 수락 및 /거래 거절 을 통해 거래를 진행해주세요.",
			"safedeal-state-0" => "====== 거래상태 ======",
			"safedeal-state-1" => "========= =========",
			"safedeal-state-2" => "{%0}님께 거래를 요청",
			"safedeal-state-3" => "아이템 : {%0}",
			"safedeal-state-4" => "개수 : {%0}개",
			"safedeal-state-5" => "가격 : {%0}{%Monetary-Unit}",
			"safedeal-state-6" => "받거나 보낸 거래요청이 없습니다.",
			"safedeal-accept-0" => "거래가 무사히 성사되었습니다.",
			"safedeal-accept-1" => "인벤토리창을 확인해보세요 !",
			"safedeal-accept-2" => "자신의 돈을 확인해보세요 !",
			"safedeal-accept-fail-0" => "받은 거래요청이 없습니다.",
			"safedeal-accept-fail-1" => "거래요청자가 서버에서 나가, 거래를 진행할 수 없습니다.",
			"safedeal-accept-fail-2" => "아이템이 부족하여 거래를 진행할 수 없습니다.",
			"safedeal-accept-fail-3" => "돈이 부족하여 거래를 진행할 수 없습니다.",
			"safedeal-accept-fail-4" => "상대방의 돈이 부족하여 거래를 진행할 수 없습니다.",
			"safedeal-accept-fail-5" => "상대방의 아이템이 부족하여 거래를 진행할 수 없습니다.",
			"safedeal-cancelled-fail-0" => "받은 거래요청이 없습니다.",
			"safedeal-cancelled-0" => "거래를 취소하였습니다.",
			"safedeal-cancelled-1" => "상대방이 거래를 취소하였습니다.",
			"safedeal-rejected-fail-0" => "받은 거래요청이 없습니다.",
			"safedeal-rejected-0" => "거래를 거절하였습니다.",
			"safedeal-rejected-1" => "{%0}님이 거래를 거절하였습니다.",
			"safedeal-receive-0" => "수신을 차단하였습니다.",
			"safedeal-receive-1" => "수신차단을 해제하였습니다.",
			"safedeal-deal-cancelled" => "거래가 취소되었습니다.",
			
			"itemcloud-default-mark" => "[ 클라우드 ]",
			"itemcloud-command-usage" => "/클라우드 <업로드/다운로드/추가/제거/목록/확장>",
			"itemcloud-upload-usage" => "/클라우드 업로드 <개수>",
			"itemcloud-upload-description" => "현재 들고있는 아이템을 클라우드에 업로드합니다.",
			"itemcloud-upload-0" => "업로드하실 아이템을 들어주세요 !",
			"itemcloud-upload-1" => "소유하신 아이템 개수가 부족합니다. ( {%0}개 )",
			"itemcloud-upload-2" => "클라우드에 아이템을 정상적으로 업로드하였습니다 ! ( {%0} , {%1}개 , 항목번호 : {%2} )",
			"itemcloud-upload-fail-0" => "업로드가 불가능한 아이템입니다.",
			"itemcloud-upload-fail-1" => "클라우드의 용량이 부족합니다. ( /클라우드 확장 )",
			"itemcloud-download-usage" => "/클라우드 다운로드 <항목번호>",
			"itemcloud-download-description" => "클라우드에 업로드한 아이템을 다운로드합니다.",
			"itemcloud-download-fail-0" => "존재하지 않는 항목입니다.",
			"itemcloud-download-0" => "클라우드에서 아이템을 성공적으로 다운로드하였습니다 ! ( {%0} , {%1}개 )",
			"itemcloud-download-1" => "인벤토리창을 확인해보세요 !",
			"itemcloud-add-usage" => "/클라우드 추가 <유저명> <아이템[:데미지]> [개수]",
			"itemcloud-remove-fail-0" => "존재하지 않는 항목입니다.",
			"itemcloud-remove-0" => "클라우드에서 해당 아이디의 아이템을 삭제하였습니다.",
			"itemcloud-list" => "=======클라우드 리스트 ( {%0} / {%1} )=======",
			"itemcloud-list2" => "[ID]  아이템    업로드 날짜",
			"itemcloud-list-item" => "[ {%0} ] {%1} {%2}개   ( {%3} )",
			"itemcloud-list-0" => "업로드된 아이템이 없습니다.",
			"itemcloud-list-1" => "대상 유저를 찾을 수 없습니다.",
			"itemcloud-list-2" => "본 페이지에는 업로드된 아이템이 없습니다.",
			"itemcloud-expand-0" => "돈이 부족하여, 확장을 진행할 수 없습니다. ( 확장비 : {%0}{%Monetary-Unit} )",
			"itemcloud-expand-1" => "클라우드 확장을 진행하시겠습니까?",
			"itemcloud-expand-2" => "확장을 진행하시려면 /클라우드 확장 을, 취소하시려면 /클라우드 작업취소 를 입력해주세요.",
			"itemcloud-expand-3" => "확장비 : {%0} , 확장용량 : {%1}",
			"itemcloud-expand-4" => "클라우드 용량 확장을 성공하였습니다. ( {%0} => {%1} )",
			"itemcloud-work-cancelled" => "진행하시려던 모든 클라우드 작업을 취소하였습니다.",
			
			'vmachine-default-mark' => '[ 자판기 ]',
			'vmachine-command-usage' => '/자판기 <설치/물품변경/물품꺼내기/수익인출/제거>',
			'vmachine-install-0' => '아이템 자판기를 설치하시겠습니까? ( 설치비용 : {%0}{%Monetary-Unit} ) ',
			'vmachine-install-1' => "설치를 하시려면 '/자판기 설치 y'를 입력해주세요.",
			'vmachine-install-2' => '터치하여 자판기를 설치해주세요.',
			'vmachine-install-3' => "자판기 설치를 중단하시려면 '/자판기 작업취소' 를 입력해주세요.",
			'vmachine-install-4' => '자판기를 설치하였습니다.',
			'vmachine-install-fail-0' => '자판기를 설치하는데에 드는 비용이 부족합니다. ( 보유 : {%0}{%Monetary-Unit} )',
			'vmachine-replace-usage-0' => '/자판기 물품변경 <개수> <개당가격>',
			'vmachine-replace-usage-1' => '물품으로 둘 아이템을 들고, 명령어를 입력해주세요.',
			'vmachine-replace-0' => '물품으로 둘 자판기를 터치해주세요.',
			'vmachine-replace-1' => "자판기 물품변경을 중단하시려면 '/자판기 작업취소' 를 입력해주세요.",
			'vmachine-replace-2' => '자판기 물품을 변경하였습니다.',
			'vmachine-replace-3' => '기존에 있던 물품 {%0} {%1}개는 지급되었습니다.',
			'vmachine-replace-fail-0' => '본 아이템은 물품으로 둘 수 없는 아이템입니다.',
			'vmachine-takeout-0' => '물품을 꺼낼 자판기를 터치해주세요.',
			'vmachine-takeout-1' => '{%0} {%1}개를 자판기에서 꺼냈습니다.',
			'vmachine-info-0' => '자판기 주인 : {%0}',
			'vmachine-info-1' => '남은 재고 : {%0}개',
			'vmachine-info-2' => '{%0} 판매중 ( 남은 재고 : {%1}개, 개당 가격 : {%2}{%Monetary-Unit} )',
			'vmachine-info-2_1' => '판매중인 물품이 없습니다.',
			'vmachine-info-3' => '자판기 누적수익 : {%0}{%Monetary-Unit}',
			'vmachine-info-4' => '현재 수익 (출금가능액) : {%0}{%Monetary-Unit}',
			'vmachine-buy-0' => "{%0}을(를) 구매하시려면 '/구매 <수량>' 을 입력해주세요. ( 개당 {%1}{%Monetary-Unit} )",
			'vmachine-buy-1' => "{%0} {%1}개를 {%2}{%Monetary-Unit}로 구매하였습니다.",
			'vmachine-buy-fail-0' => '자판기의 재고가 부족합니다. ( 재고 : {%0}개 )',
			'vmachine-buy-fail-1' => '돈이 부족합니다. ( {%0}/{%1}{%Monetary-Unit} )',
			'vmachine-buy-fail-2' => '구매가 불가능한 아이템입니다.',
			'vmachine-withdrawal-0' => '수익을 인출할 자판기를 터치해주세요.',
			'vmachine-withdrawal-1' => '자판기 수익 {%0}{%Monetary-Unit}을 인출하였습니다.',
			"vmachine-remove-0" => '자판기 제거를 진행해주세요.',
			"vmachine-remove-1" => ' * 자판기 제거시 설치비용은 돌려받지 않습니다.',
			"vmachine-remove-2" => "제거를 중단하시려면 '/자판기 작업취소'를 입력해주세요.",
			"vmachine-remove-3" => "자판기 제거하였습니다.",
			"vmachine-work-cancelled" => "진행하시려던 모든 자판기 작업을 취소하였습니다.",
			"vmachine-work-already-exists" => "이미 진행중인 작업이 있습니다. ( /자판기 작업취소 )",

			"shop-default-mark" => "[ 상점 ]",
			"shop-command-usage" => "/상점 <생성/정보>",
			"shop-mode-0" => "상점 생성모드로 전환하였습니다.",
			"shop-mode-1" => "매물로 둘 아이템을 들고 유리를터치해주세요.",
			"shop-mode-2" => "일반모드로 전환하였습니다.",
			"shop-setting-0" => "구매가를 채팅창에 입력하여 설정해주세요. ( false 입력시 구매 불가능 설정 )",
			"shop-setting-1" => "판매가를 채팅창에 입력하여 설정해주세요. ( false 입력시 판매 불가능 설정 )",
			"shop-setting-2" => "상점을 정상적으로 등록하였습니다.",
			"shop-remove-0" => "상점을 제거하였습니다.",
			"shop-deal-0" => "{%0}을(를) 구매/판매 하시겠습니까? ( /구매, /판매 )",
			"shop-deal-1" => "구매가 : {%0}, 판매가 : {%1}",
			"shop-deal-2" => "{%0}을(를) {%1}{%Monetary-Unit} 으로 구매 하시겠습니까? ( /구매 )",
			"shop-deal-3" => "{%0}을(를) {%1}{%Monetary-Unit} 으로 판매 하시겠습니까? ( /판매 )",
			"shop-deal-fail-0" => "개수를 0개 이상으로 적어주세요.",
			"shop-info-0" => "상점 위치 : {%0}",
			"shop-info-1" => "구매 횟수 : {%0}",
			"shop-info-2" => "판매 횟수 : {%0}",
			"shop-info-fail-0" => "상점을 선택해주세요.",

			"buy-command-usage" => "/구매 <수량>",
			"buy-0" => "{%0}을(를) {%1}개를 {%2}{%Monetary-Unit} 으로 구매하였습니다.",
			"buy-fail-0" => "본 상점에서는 구매가 불가능한 아이템 입니다.",
			"buy-fail-1" => "돈이 부족하여 구매가 불가능합니다.",
			'buy-fail-2' => '수량을 1개 이상으로 적어주세요.',
			"sell-command-usage" => "/판매 <수량>",
			"sell-0" => "{%0}을(를) {%1}개를 {%2}{%Monetary-Unit} 으로 판매하였습니다.",
			"sell-fail-0" => "본 상점에서는 판매가 불가능한 아이템 입니다.",
			"sell-fail-1" => "아이템이 부족하여 판매가 불가능합니다.",
			
			'auction-default-mark' => '[ 경매 ]',
			'auction-command-usage' => '/경매 <등록/입찰>',
			'auction-install-usage' => '/경매 설치 <이용요금>',
			'auction-install-0' => '터치하여 경매기 설치를 진행해주세요.',
			'auction-install-1' => "경매기 설치를 중단하시려면 '/경매 작업취소' 명령어를 입력해주세요.",
			'auction-install-2' => '경매기를 설치하였습니다.',

			'auction-registration-usage' => '/경매 등록 <개수> [최저입찰가]',
			'auction-registration-usage2' => '현재 들고있는 아이템으로 경매를 등록합니다.',
			'auction-registration-0' => '경매를 등록할 경매기를 터치해주세요.',
			'auction-registration-1' => '{%0} {%1}개를 {%2}원을 최저입찰가로 경매를 등록하였습니다.',
			'auction-registration-fail-0' => '아이템을 들고 명령어를 입력해주세요.',
			'auction-registration-fail-1' => '경매에 등록할 수 없는 아이템입니다.',
			'auction-registration-fail-2' => '아이템 개수를 1개 이상으로 입력해주세요.',
			'auction-registration-fail-3' => '최저입찰가를 0원 이상으로 입력해주세요.',
			'auction-registration-fail-4' => '이미 경매가 등록된 경매기입니다.',
			'auction-registration-fail-5' => '이용요금이 부족합니다. ( {%0}/{%1} {%Monetary-Unit} )',
			'auction-registration-fail-6' => '보유하고있는 아이템 개수가 부족합니다. ( {%0}/{%1} 개 )',

			'auction-info-0' => '마감까지 남은시간 : {%0}초',
			'auction-info-1' => '아이템 : {%0} {%1}개',
			'auction-info-2' => '현재 입찰가 : {%0}{%Moneytary-Unit}',
			'auction-info-3' => "'/경매 등록' 명령어로 경매를 등록할 수 있습니다.",

			"auction-start-usage" => "/경매 시작 <개수> <최저입찰가>",
			"auction-start-0" => "들고있는 아이템으로 경매를 시작합니다. 수수료로 낙찰가의 1/10가 회수됩니다.",
			"auction-start-1" => "경매를 진행하시려면 '/경매 시작 <개수> <최저입찰가> y' 를 입력해주세요.",
			"auction-start-2" => "한번 시작한 경매는 취소할 수 없습니다.",
			"auction-start-3" => "{%0}님이 {%1} {%2}개를 {%3}{%Monetary-Unit}을 최저입찰가로 경매를 시작하였습니다.",
			"auction-start-4" => "입찰하시려면 '/경매 입찰 <가격>' 을 입력해주세요.",
			"auction-start-fail-0" => "이미 진행중인 경매가 있습니다.",
			"auction-start-fail-1" => "경매가 불가능한 아이템입니다.",
			"auction-start-fail-2" => "갖고계신 아이템개수가 부족합니다.",
			"auction-start-fail-3" => "1개 이상으로 입력해주세요 !",
			"auction-bidding-usage" => "/경매 입찰 <가격>",
			"auction-bidding-0" => "{%0}님이 {%1}{%Monetary-Unit}으로 입찰하였습니다. ( 이전 입찰가 : {%2}{%Monetary-Unit} )",
			"auction-bidding-1" => "입찰하시려면 '/경매 입찰 <가격>' 을 입력해주세요.",
			"auction-successfulBid-0" => "{%0}초내로 입찰되지않으면 {%1}님께 {%2} {%3}개가 {%4}{%Monetary-Unit}(으)로 낙찰됩니다. ( /경매 입찰 <가격> )",
			"auction-successfulBid-1" => "{%0}초내로 입찰되지않으면 경매가 취소됩니다. ( /경매 입찰 <가격> )",
			"auction-successfulBid-2" => "{%0}님께 {%1} {%2}개가 {%3}{%Monetary-Unit}(으)로 낙찰되었습니다.",
			"auction-successfulBid-3" => "인벤토리에서 {%0} {%1}개를 확인하세요!",
			"auction-successfulBid-4" => "{%0}{%Monetary-Unit}이 지급되었습니다. 확인해주세요!",
			"auction-successfulBid-fail-0" => "경매 진행자 또는 낙찰자가 오프라인이여서 경매가 취소되었습니다.",
			"auction-successfulBid-fail-1" => "경매 진행자의 아이템개수가 부족하여 경매가 취소되었습니다.",
			"auction-successfulBid-fail-2" => "낙찰자의 돈이 부족하여 경매가 취소되었습니다.",
			"auction-successfulBid-fail-3" => "입찰자가 없어 경매가 취소되었습니다.",
			"auction-bidding-fail-0" => "현재 진행중인 경매가 없습니다.",
			"auction-bidding-fail-1" => "입찰가가 낮습니다. {%0}{%Monetary-Unit} 이상으로 입찰해주세요. ( 현재 입찰가 : {%1}{%Monetary-Unit} )",
			"auction-bidding-fail-2" => "돈이 부족합니다. ( 현재 입찰가 : {%0} / 현재 내돈 : {%1}{%Monetary-Unit} )",

			"craft-default-mark" => "[ 조합 ]",
			"craft-command-usage" => "/조합 <개수>",
			"craft-create-0" => "조합 생성모드로 전환하였습니다.",
			"craft-create-1" => "조합결과물로 둘 아이템을 들고 유리를터치해주세요.",
			"craft-create-2" => "일반모드로 전환하였습니다.",
			"craft-setting-0" => "재료를 들고 개수를 채팅창에 명령어형식으로 입력하여 설정해주세요. ( /fin <제작할개수> <가격> => 재료 종료 )",
			'craft-setting-1' => '{%0} {%1}개를 레시피에 등록하였습니다.',
			'craft-setting-2' => "조합대를 정상적으로 등록하였습니다.",
			"craft-remove-0" => "조합대를 제거하였습니다.",
			"craft-info-0" => "상점 위치 : {%0}",
			"craft-info-1" => "조합 횟수 : {%0}",
			'craft-info-fail-0' => "조합대를 선택해주세요.",
			'craftItem-command-usage' => "/구매 <수량>",
			'craftItem-0' => "{%0}{%Monetary-Unit}으로 조합 하시겠습니까? ( /조합 <수량> )",
			'craftItem-1' => '{%0} {%1}개를 조합하였습니다.',
			'craftItem-fail-0' => "조합대를 선택해주세요.",
			'craftItem-fail-1' => "조합할 개수를 1개 이상으로 적어주세요.",
			'craftItem-fail-2' => "돈이 부족하여 조합이 불가능합니다.",
			'craftItem-fail-3' => '재료가 부족하여 조합이 불가능합니다. ( {%0} {%1}/{%2} )',

			"cant-use-command" => "당신은 이 명령어를 사용할 권한이 없습니다",
			"must-use-in-game" => "본 명령어는 인게임 내에서만 사용이 가능합니다."
		)))->getAll();

		if(file_exists($this->plugin->getDataFolder() . "iDeal.yml")) {
			$this->plugin->getLogger()->warning("설정파일이 'iDeal.yml' 입니다. 1.0.7v 이후로 'config.yml' 으로 변경되었습니다.");
			$this->plugin->getLogger()->warning("곧 'iDeal.yml' 파일 로드는 지원이 중단됩니다. 'config.yml' 으로 변경바랍니다.");
			$this->plugin->setting = (new Config($this->plugin->getDataFolder() . "iDeal.yml", Config::YAML))->getAll();
		}else{
			$this->plugin->saveResource("config.yml", false);
			$this->plugin->setting = (new Config ( $this->plugin->getDataFolder() . "config.yml", Config::YAML))->getAll();
		}

		if(!isset(Config::$formats[$this->plugin->setting['saveFormat']])) {
			$this->plugin->getLogger()->error(TextFormat::RED."'{$this->plugin->setting['saveFormat']}' 은(는) 지원하지 않는 저장형식입니다.");
		}else{
			$saveFormat = Config::$formats[$this->plugin->setting['saveFormat']];
			$this->plugin->saveResource("itemName.yml", false);
			$this->plugin->itemName = (new Config ( $this->plugin->getDataFolder () . "itemName.yml", Config::YAML))->getAll ();
	
			if(!file_exists($this->plugin->getDataFolder () . "itemCloud.".strtolower($this->plugin->setting['saveFormat']))) { $temp['newFormat'] = true; }

			$this->plugin->itemCloudConfig = (new Config ( $this->plugin->getDataFolder () . "itemCloud.".strtolower($this->plugin->setting['saveFormat']), $saveFormat));
			$this->plugin->VMachineConfig = (new Config ( $this->plugin->getDataFolder () . "VendingMachine.".strtolower($this->plugin->setting['saveFormat']), $saveFormat));
			$this->plugin->shopConfig = (new Config ( $this->plugin->getDataFolder () . "shop.".strtolower($this->plugin->setting['saveFormat']), $saveFormat));
			$this->plugin->craftConfig = (new Config ( $this->plugin->getDataFolder () . "craft.".strtolower($this->plugin->setting['saveFormat']), $saveFormat));

			if(isset($temp['newFormat'])) {
				$this->plugin->itemCloudConfig->setAll((new Config ( $this->plugin->getDataFolder () . 'itemCloud.yml'))->getAll());
				$this->plugin->itemCloudConfig->save();
				$this->plugin->VMachineConfig->setAll((new Config ( $this->plugin->getDataFolder () . 'VendingMachine.yml'))->getAll());
				$this->plugin->VMachineConfig->save();
				$this->plugin->shopConfig->setAll((new Config($this->plugin->getDataFolder() . 'shop.yml'))->getAll());
				$this->plugin->shopConfig->save();
				$this->plugin->craftConfig->setAll((new Config ( $this->plugin->getDataFolder () . 'craft.yml'))->getAll());
				$this->plugin->craftConfig->save();
			}

			$this->plugin->itemCloud = $this->plugin->itemCloudConfig->getAll();
			$this->plugin->VMachine = $this->plugin->VMachineConfig->getAll();
			$this->plugin->shop = $this->plugin->shopConfig->getAll();
			$this->plugin->craft = $this->plugin->craftConfig->getAll();
		}
	}

	public function registerCommand($name, $permission, $description = "", $usage = "") {
		$command = new PluginCommand($name, $this->plugin);
		$command->setDescription($description);
		$command->setPermission($permission);
		$command->setUsage($usage);
		$this->plugin->getServer()->getCommandMap()->register($name, $command);
	}

	public function checkUpdate($version) {
		$plugin = json_decode(Utils::getUrl("http://hn.pe.kr/plugin/plugins/iDeal/plugin.php?version={$version}"), true);
		if($plugin['update']) { $this->plugin->getLogger()->notice("iDeal 플러그인의 최신버전이 있습니다. (v{$plugin['latest-version']})"); }
		else{ $this->plugin->getLogger()->notice("현재 최신버전의 iDeal 플러그인을 사용중입니다."); }
	}
}
?>