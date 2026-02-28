# Kubeo.lol-WebGL-Client
We have decided to open source Kubeo.lol made in BabylonJS and SocketIO as a community toolchain for users to contribute to we only ask you to open source yours as well!
What's required?
First do server.js
Change avatar url the file must contain api logic like this
"{"id":1,"ava_id":3,"hat1":10,"hat2":185,"hat3":144,"face":0,"shirt":205,"tshirt":0,"pants":0,"shoes":0,"tool":0,"colors":{"hC":"255\/255, 140\/255, 0\/255","lAC":"255\/255, 140\/255, 0\/255","rAC":"255\/255, 140\/255, 0\/255","tC":"130\/255, 78\/255, 86\/255","lLC":"0\/255, 0\/255, 0\/255","rlC":"0\/255, 0\/255, 0\/255","lFC":"130\/255, 78\/255, 86\/255","rFC":"130\/255, 78\/255, 86\/255"}}"
this also works for Kubeo's godot client (fun fact)
Start off static if you want then work your way up to dynamic
Then go to index.php
Change all endpoints to assets from your domain

Now do node server.js
And you're done!
