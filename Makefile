.PHONY: firstStep
# Подключить RabbitMQ бота к правильной сети
first.step:
	docker network connect telegram-messages-handler_botnet rabbitmq

.PHONY: checkNetwork
# проверить, что все консьюмеры и воркеры в одной сети
check.network:
	docker network inspect telegram-messages-handler_botnet

.PHONY: logs
# show logs for telegram.raw queue
telegram.raw:
	# Use the --follow (-f) flag to stream logs in real time (Ctrl+C to stop)
	docker logs telegram-messages-handler-telegram-consumer-1

# show logs for laravel.jobs queue
laravel.jobs:
	# Use the --follow (-f) flag to stream logs in real time (Ctrl+C to stop)
	docker logs telegram-messages-handler-queue-worker-1

# show logs for exchange.orders queue
exchange.orders:
	# Use the --follow (-f) flag to stream logs in real time (Ctrl+C to stop)
	docker logs telegram-messages-handler-exchange-worker-1

.PHONY: DB
connectToDB:
	docker exec -it telegram-messages-handler-pgsql-1 psql -U sail -d laravel

.PHONY: RabbitMQ
showQueuesInfo:
	docker exec -it rabbitmq rabbitmqctl list_queues name messages_ready messages_unacknowledged consumers state

putMessageToQueue:
# Положить сообщение в очередь telegram.raw Нужно заменить json на нужный
	docker exec -it rabbitmq rabbitmqadmin publish exchange=amq.default routing_key=telegram.raw payload='{"data":{"message":{"message":"\ud83d\udfe2 LONG  - $ORDI - RISK ORDER - SMALL VOL\n-  Entry: 4.583\n- Entry limit: 4.475\n- SL: 4.384\n\u26a0\ufe0f\n Disclaimer\nThis is not financial advice. Trade at your own risk."}},"channelId":"-1001573488012"}'
