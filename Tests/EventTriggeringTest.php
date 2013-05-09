<?php

/**
 * This file is part of the GlorpenPropelBundle package.
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @license GPLv3
 */

namespace Glorpen\Propel\PropelBundle\Tests;

use Glorpen\Propel\PropelBundle\Events\QueryEvent;

use Glorpen\Propel\PropelBundle\Events\PeerEvent;

use Glorpen\Propel\PropelBundle\Events\ConnectionEvent;

use Glorpen\Propel\PropelBundle\Connection\EventPropelPDO;

use Glorpen\Propel\PropelBundle\Services\ContainerAwareModel;

use Symfony\Component\EventDispatcher\ContainerAwareEventDispatcher;

use Glorpen\Propel\PropelBundle\Dispatcher\EventDispatcherProxy;

use Glorpen\Propel\PropelBundle\Tests\PropelTestCase;

use Glorpen\Propel\PropelBundle\Tests\Fixtures\Model\Book;

use Glorpen\Propel\PropelBundle\Tests\Fixtures\Model\BookQuery;

use Glorpen\Propel\PropelBundle\Tests\Fixtures\Model\BookPeer;

/**
 * @author Arkadiusz Dzięgiel
 */
class EventTriggeringTest extends PropelTestCase {
	
	public function setUp()
	{
		$this->loadAndBuild();
	}
	
	private $oldConnection;
	
	protected function replaceConnection(){
		$con = new EventPropelPDO('sqlite::memory:');
		$con->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_WARNING);
		$this->oldConnection = \Propel::getConnection('books');
		\Propel::setConnection('books', $con);
	}
	
	protected function revertConnection(){
		\Propel::setConnection('books', $this->oldConnection);
	}
	
	public function testContainerSetting(){
		
		EventDispatcherProxy::setDispatcherGetter(function(){
			$c = $this->getContainer();
			$d = new ContainerAwareEventDispatcher($c);
			
			$d->addListener('model.construct', array(new ContainerAwareModel($c), 'onModelConstruct'));
			
			return $d;
		});
		
		$b = new Book();
		$this->assertTrue($b->hasContainer(), 'Container is set on object creation');
	}
	
	public function setUpEventHandlers(){
		$triggered = new \stdClass();
		$events = func_get_args();
		
		foreach($events as $e){
			$triggered->{$e} = 0;
		}
		
		EventDispatcherProxy::setDispatcherGetter(function() use (&$triggered, $events){
			$c = $this->getContainer();
			$d = new ContainerAwareEventDispatcher($c);
				
			foreach($events as $e){
				$d->addListener($e, function() use($e, &$triggered){
					$triggered->{$e}++;
				});
			}
		
			return $d;
		});
		
		return $triggered;
	}
	
	public function assertEventTriggered($msg, $ctx){
		$args = array_slice(func_get_args(), 2);
		$ctx = (array)$ctx;
		$k = array_combine(array_keys($ctx), $args);
		foreach($k as $key=>$val){
			$this->assertEquals($val, $ctx[$key], $msg.' for '.$key);
		}
	}
	
	public function testEventsTriggering(){
		
		//model
		
		$ctx = $this->setUpEventHandlers('construct','model.construct');
		$m = new Book();
		$this->assertEventTriggered('On new model construct', $ctx, 1,1);
		
		$ctx = $this->setUpEventHandlers('model.insert.post','model.insert.pre', 'model.save.pre', 'model.save.post');
		$m->save();
		$this->assertEventTriggered('On new model insert', $ctx, 1,1,1,1);
		
		$ctx = $this->setUpEventHandlers('model.update.post','model.update.pre', 'model.save.pre', 'model.save.post', 'update.pre', 'update.post');
		$m->setTitle('title');
		$m->save();
		$this->assertEventTriggered('On model update', $ctx, 1,1,1,1,1,1);
		
		$ctx = $this->setUpEventHandlers('model.delete.post','model.delete.pre', 'delete.pre', 'delete.post');
		$m->delete();
		$this->assertEventTriggered('On model delete', $ctx, 1,1,2,2);
		
		//query
		
		$ctx = $this->setUpEventHandlers('construct','query.construct');
		$q = new BookQuery();
		$this->assertEventTriggered('On new query construct', $ctx, 1,1);
		
		$ctx = $this->setUpEventHandlers('query.update.post','query.update.pre', 'update.pre', 'update.post');
		$q->update(array('Title'=>'the title'));
		$this->assertEventTriggered('On query update', $ctx, 1,1,1,1);
		
		$ctx = $this->setUpEventHandlers('query.delete.post','query.delete.pre', 'delete.pre', 'delete.post');
		$q->filterByTitle('test')->delete();
		$this->assertEventTriggered('On query delete', $ctx, 1,1,1,1);
		
		$ctx = $this->setUpEventHandlers('query.select.pre');
		$q->find();
		$this->assertEventTriggered('On query select', $ctx, 1);
		
		//connection
		
		$ctx = $this->setUpEventHandlers('connection.create');
		$this->replaceConnection();
		$this->assertEventTriggered('On connection create', $ctx, 1);
		
		$ctx = $this->setUpEventHandlers('connection.commit.pre','connection.commit.post');
		$con = \Propel::getConnection();
		$con->beginTransaction();
		$con->beginTransaction();
		
		$con->commit();
		$this->assertEventTriggered('On connection nested commt', $ctx, 0,0);
		$con->commit();
		$this->assertEventTriggered('On connection commt', $ctx, 1,1);
		
		$ctx = $this->setUpEventHandlers('connection.rollback.pre','connection.rollback.post');
		$con = \Propel::getConnection();
		$con->beginTransaction();
		$con->beginTransaction();
		
		$con->rollBack();
		$this->assertEventTriggered('On connection nested rollback', $ctx, 0,0);
		$con->rollBack();
		$this->assertEventTriggered('On connection rollback', $ctx, 1,1);
		
		$this->revertConnection();
	}
	
	public function testEvents(){
		$con = \Propel::getConnection();
		$e = new ConnectionEvent($con);
		$this->assertSame($con, $e->getConnection());
		
		$e = new PeerEvent($cls='SomeClass');
		$this->assertEquals($cls, $e->getClass());
		
		$e = new QueryEvent($q=new BookQuery());
		$this->assertSame($q, $e->getQuery());
	}
}