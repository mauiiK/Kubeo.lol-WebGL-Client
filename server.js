const express = require("express");
const http = require("http");
const { Server } = require("socket.io");
const path = require("path");
const fetch = (...args) => import('node-fetch').then(({default: fetch}) => fetch(...args));
const app = express();
const server = http.createServer(app);
const io = new Server(server, {
    cors: { origin: "*", methods: ["GET", "POST"] },
    pingTimeout: 30000,
    pingInterval: 10000
});
const players = {};
const SPAWN_RANGE = 10;
const SPAWN_HEIGHT = 15;
const SPAWN_MIN_DIST = 4;
const MAX_SPEED = 10; // allow faster movement to avoid corrections
const VOID_THRESHOLD = -30;
const VOID_RESET = { x: 0, y: 15, z: 0 };
const MSG_MAX_LENGTH = 500;
app.get("/socket.io/socket.io.js", (req, res) => {
    res.sendFile(path.join(__dirname, "node_modules", "socket.io", "client-dist", "socket.io.js"));
});
async function getUsername(socket) {
    try {
        const res = await fetch("https://your.site/username", {
            headers: { cookie: socket.handshake.headers.cookie || "" }
        });
        if (!res.ok) throw 0;
        const data = await res.json();
        return data.username?.trim() || null;
    } catch {
        return "Player_" + Math.floor(Math.random() * 1000);
    }
}
async function getAvatar(socket) {
    try {
        const res = await fetch("https://your.site/avaApi", {
            headers: { cookie: socket.handshake.headers.cookie || "" }
        });
        if (!res.ok) throw 0;
        const data = await res.json();
        return data;
    } catch {
        return null;
    }
}
io.on("connection", async (socket) => {
    const username = await getUsername(socket);
    const avatar = await getAvatar(socket);
    // try to avoid spawning directly on top of another player
    let spawnX, spawnZ;
    do {
        spawnX = Math.random() * SPAWN_RANGE * 2 - SPAWN_RANGE;
        spawnZ = Math.random() * SPAWN_RANGE * 2 - SPAWN_RANGE;
    } while (Object.values(players).some(p => {
        const dx = spawnX - p.position.x;
        const dz = spawnZ - p.position.z;
        return Math.sqrt(dx*dx + dz*dz) < SPAWN_MIN_DIST;
    }));
    // Ensure spawn height is always safe (above ground minimum 15)
    const spawnHeight = Math.max(SPAWN_HEIGHT, 15);
    players[socket.id] = {
        id: socket.id,
        position: { x: spawnX, y: spawnHeight, z: spawnZ },
        rotation: { y: 0 },
        name: username || "Player_" + Math.floor(Math.random() * 1000),
        avatar: avatar || null
    };
    console.log(`📡 ${players[socket.id].name} connected (${socket.id})`);
    // ensure no player has invalid position before sharing
    Object.values(players).forEach(p => {
        if (!p.position || [p.position.x, p.position.y, p.position.z].some(v => typeof v !== 'number')) {
            //console.warn(`Sanitizing invalid position for ${p.id}`);
            p.position = { x: 0, y: SPAWN_HEIGHT, z: 0 };
        }
    });
    console.log("Sending currentPlayers:", Object.keys(players), Object.values(players).map(p => ({id: p.id, name: p.name, position: p.position})));
    socket.emit("currentPlayers", players);
    socket.broadcast.emit("newPlayer", players[socket.id]);
    io.emit("chatMessage", { sender: "[SERVER]", text: `${players[socket.id].name} has joined the game!` });
    socket.on("chatMessage", (msg) => {
        if (!players[socket.id]) return;
        const sender = players[socket.id].name;
        const text = String(msg).substring(0, MSG_MAX_LENGTH).trim();
        if (text) {
            console.log(`[${sender}] ${text}`);
            io.emit("chatMessage", { sender, text });
        }
    });
    socket.on("playerMove", (data) => {
        const player = players[socket.id];
        if (!player) return;
        if (!data.position || !data.rotation) return;
        const {x, y, z} = data.position;
        // ensure numeric coordinates, otherwise ignore the move
        if ([x, y, z].some(v => typeof v !== 'number' || isNaN(v))) {
            console.warn(`Ignoring malformed move from ${socket.id}`, data.position);
            return;
        }
        const dx = x - player.position.x;
        const dy = y - player.position.y;
        const dz = z - player.position.z;
        const dist = Math.sqrt(dx*dx + dy*dy + dz*dz);
        if (dist > MAX_SPEED) {
            socket.emit("serverCorrection", player);
            return;
        }
        player.position = {x, y, z};
        player.rotation = data.rotation;
        if (y < VOID_THRESHOLD) {
            player.position = VOID_RESET;
        }
        socket.broadcast.emit("playerMoved", player);
    });
    socket.on("playerJump", () => {
        // simply tell everyone else that this id jumped
        socket.broadcast.emit("playerJump", socket.id);
    });
    socket.on("disconnect", () => {
        const playerName = players[socket.id]?.name || "Unknown";
        console.log(`${playerName} disconnected`);
        delete players[socket.id];
        io.emit("playerDisconnected", socket.id);
        io.emit("chatMessage", { sender: "[SERVER]", text: `${playerName} has left the game.` });
    });
});
const PORT = process.env.PORT || 3000;
server.listen(PORT, () => {
    console.log(`KUBEO Server running on port ${PORT}`);
});
