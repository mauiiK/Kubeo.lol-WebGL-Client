<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8"/>
<title>Kubeo Client Prototype</title>
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no"/>
<link rel="stylesheet" href="w3.css"/>
<script src="https://cdn.babylonjs.com/babylon.js"></script>
<script src="https://cdn.babylonjs.com/loaders/babylonjs.loaders.min.js"></script>
<script src="https://cdn.babylonjs.com/gui/babylon.gui.min.js"></script>
<script src="../dist/CharacterController.js"></script>
<script src="https://yoursite/socket.io/socket.io.js"></script>
<link rel="stylesheet" href="game.css">
<script>
const B = BABYLON;
const G = B.GUI;
const C = G.Control;
const DEBUG = false; // tsubgle verbose lsubging
const ASSET_BASE = "https://yoursite/path/assets/";
const DEFAULTS = {face: "default_face", shirt: 74, pants: 74};
const STATE = {
    socket: null, scene: null, canvas: null, players: {}, multiplayerEnabled: false,
    lastEmit: 0, playerLimbs: null, cc1: null, adv: null, addChatMessage: null,
    pendingMoves: {}, nametagContainer: null,
    // snow effect state (on by default)
    snowEnabled: true,
    snowSystem: null
};
const textureCache = {}; // shared texture cache for all avatars
// candy model template will be loaded once; pending spawn positions held until ready
let candyTemplate = null;
let pendingCandySpawns = [];
// helper to spawn a candy at a given position once the template is available
const spawnCandyAt = pos => {
    const s = STATE.scene;
    if (!s) return;
    if (!candyTemplate) {
        // either not yet loaded or load failed, queue for later but also create a simple placeholder
        pendingCandySpawns.push(pos.clone());
        // create a fallback simple candy so user still sees something
        const fallback = B.MeshBuilder.CreateCylinder("candy_fallback_"+Date.now(), {diameter:0.5,height:0.2}, s);
        const mat = new B.StandardMaterial("candyFallbackMat", s);
        mat.diffuseTexture = new B.Texture("https://cdn.yoursite/img/assets/176.png?item=151", s);
        mat.diffuseTexture.hasAlpha = true;
        fallback.material = mat;
        fallback.position.copyFrom(pos);
        fallback.scaling = new B.Vector3(0.2,0.2,0.2);
        fallback.isPickable = true;
        fallback.checkCollisions = false;
        s.registerBeforeRender(() => {
            fallback.rotation.y += 0.01;
            fallback.position.y += Math.sin(performance.now() * 0.003) * 0.002;
        });
        return;
    }
    const candy = candyTemplate.clone("candy_" + Date.now());
    candy.isPickable = true;
    candy.checkCollisions = false;
    candy.position.copyFrom(pos);
    candy.scaling = new B.Vector3(0.2, 0.2, 0.2);
    candy.isVisible = true; // clones start invisible if template was hidden
    // simple hover/rotation animation
    s.registerBeforeRender(() => {
        candy.rotation.y += 0.01;
        candy.position.y += Math.sin(performance.now() * 0.003) * 0.002;
    });
};
// snow particle system creation
const createSnow = scene => {
    // create a small circular texture procedurally so we never get ugly squares
    const size = 64;
    const dyn = new B.DynamicTexture("snowCircle", {width: size, height: size}, scene, false);
    const ctx = dyn.getContext();
    ctx.clearRect(0, 0, size, size);
    ctx.fillStyle = "white";
    ctx.beginPath();
    ctx.arc(size/2, size/2, size/2 - 1, 0, Math.PI*2);
    ctx.fill();
    dyn.update();
    // relatively modest particle count to avoid slowdown on slower devices
    const snow = new B.ParticleSystem("snow", 200, scene);
    snow.particleTexture = dyn; // use procedural circle
    snow.particleTexture.hasAlpha = true;
    snow.emitter = new B.Vector3(0, 10, 0);
    snow.minEmitBox = new B.Vector3(-50, 0, -50);
    snow.maxEmitBox = new B.Vector3(50, 0, 50);
    snow.direction1 = new B.Vector3(-1, -2, -1);
    snow.direction2 = new B.Vector3(1, -2, 1);
    snow.minSize = 0.1;
    snow.maxSize = 0.5;
    snow.minLifeTime = 3;
    snow.maxLifeTime = 5;
    snow.emitRate = 200;
    snow.gravity = new B.Vector3(0, -0.1, 0);
    snow.blendMode = B.ParticleSystem.BLENDMODE_STANDARD;
    snow.start();
    // control start/stop in render loop
    scene.registerBeforeRender(() => {
        const fps = scene.getEngine().getFps();
        // disable snow on very low fps, re-enable when performance recovers
        if (fps < 20 && snow.isStarted) {
            snow.stop();
        } else if (fps > 25 && !snow.isStarted && STATE.snowEnabled) {
            snow.start();
        }
        if (!STATE.snowEnabled && snow.isStarted) snow.stop();
        // very simple hardware scaling adjustment to cut resolution when lagging
        const now = performance.now();
        const eng = scene.getEngine();
        if (!eng._lastScaleTime) eng._lastScaleTime = now;
        if (now - eng._lastScaleTime > 2000) {
            if (fps < 25) eng.setHardwareScalingLevel(2);
            else if (fps > 40) eng.setHardwareScalingLevel(1);
            eng._lastScaleTime = now;
        }
        // keep emitter over player's head if available
        if (STATE.playerRoot) {
            snow.emitter.copyFrom(STATE.playerRoot.position.add(new B.Vector3(0, 10, 0)));
        }
    });
    STATE.snowSystem = snow;
};
const loadAvatarModel = (scene, avatarData = {}, idSuffix = "", existingRoot = null) => { // local/remote promise resolve playerroot, limbs once imported idSuffix appended dynamic endpoints for async
    return new Promise(resolve => {
        if (DEBUG) console.lsub("[LOAD] Starting load, avatarData:", avatarData);
        const fID = avatarData.face || DEFAULTS.face;
        const shirtID = avatarData.shirt || DEFAULTS.shirt;
        const pantsID = avatarData.pants || DEFAULTS.pants;
        const hats = [avatarData.hat1, avatarData.hat2, avatarData.hat3].filter(h => h > 0);
        const colors = avatarData.colors || {};
        if (DEBUG) console.lsub("[LOAD] Asset IDs - face:", fID, "shirt:", shirtID, "pants:", pantsID);
        const playerRoot = existingRoot || new B.Mesh("avatar" + idSuffix, scene);
        //playerRoot.rotation.y = Math.PI;
        const limbs = existingRoot?.limbs || {};
        const getTexture = url => textureCache[url] ||= new B.Texture(url, scene);
        const createMaterial = (name, textureUrl, color) => {
            const mat = new B.StandardMaterial(name + idSuffix, scene);
            if (textureUrl) mat.diffuseTexture = getTexture(textureUrl);
            if (color) mat.diffuseColor = parseColor(color);
            return mat;
        };
        const loadHat = (id, parent) => {
            B.SceneLoader.ImportMesh("", "", ASSET_BASE + id + ".obj", scene, meshes => {
                const mat = createMaterial("hat_" + id, ASSET_BASE + id + ".png");
                meshes.forEach(m => {
                    m.parent = parent;
                    m.rotation.y = Math.PI / 2;
                    m.material = mat;
                    m.isPickable = false;
                });
            });
        };
        const parts = ["head.obj", "torso.obj", "leftarm.obj", "rightarm.obj", "leftleg.obj", "rightleg.obj"];
        let loaded = 0;
        parts.forEach(partFile => {
            const fullUrl = ASSET_BASE + partFile;
            if (DEBUG) console.lsub("[LOAD] Requesting", fullUrl);
            B.SceneLoader.ImportMesh("", ASSET_BASE, partFile, scene, meshes => {
                if (DEBUG) console.lsub("[LOAD] Imported", partFile, "count:", meshes.length);
                meshes.forEach(mesh => {
                    if (DEBUG) console.lsub("[LOAD]   mesh:", mesh.name, "visibility:", mesh.visibility);
                    mesh.parent = playerRoot;
                    mesh.position = B.Vector3.Zero();
                    mesh.rotation.y = Math.PI;
                    mesh.checkCollisions = false;
                    mesh.isPickable = false;
                    const name = (mesh.name + partFile).toLowerCase();
                    if (name.includes("head")) {
                        limbs.head = mesh;
                        mesh.rotation.y = Math.PI / 2;
                        mesh.material = createMaterial("head_mat", null, colors.hC);
                        if (!limbs.faceOverlay) { // Prevent duplicate faces - only create if not already
                            const faceOverlay = mesh.clone("face" + idSuffix);
                            faceOverlay.parent = mesh;
                            faceOverlay.position = new B.Vector3(0, 0, -0.01);
                            faceOverlay.renderingGroupId = 1;
                            const faceTex = getTexture(ASSET_BASE + fID + ".png");
                            const faceMat = new B.StandardMaterial("faceMat" + idSuffix, scene);
                            Object.assign(faceMat, {
                                diffuseTexture: faceTex, emissiveTexture: faceTex,
                                useAlphaFromDiffuseTexture: true, transparencyMode: B.Material.MATERIAL_ALPHABLEND
                                });
                                // default depth testing (face should be occluded by world objects)
                            faceTex.hasAlpha = true;
                            faceOverlay.material = faceMat;
                            limbs.faceOverlay = faceOverlay;
                        }
                        hats.forEach(id => loadHat(id, mesh));
                    } else if (name.includes("torso")) {
                        limbs.torso = mesh;
                        mesh.material = createMaterial("torso_mat", ASSET_BASE + shirtID + ".png", colors.tC);
                    } else if (name.includes("arm")) {
                        const isLeft = name.includes("left");
                        limbs[isLeft ? "leftArm" : "rightArm"] = mesh;
                        mesh.material = createMaterial("arm_mat", ASSET_BASE + shirtID + ".png", isLeft ? colors.lAC : colors.rAC);
                    } else if (name.includes("leg")) {
                        const isLeft = name.includes("left");
                        limbs[isLeft ? "leftLeg" : "rightLeg"] = mesh;
                        mesh.material = createMaterial("leg_mat", ASSET_BASE + pantsID + ".png", isLeft ? colors.lLC : colors.rlC);
                    }
                });
                loaded++;
                if (DEBUG) console.lsub("[LOAD] Prsubress:", loaded, "/", parts.length);
                if (loaded === parts.length) {
                    if (DEBUG) console.lsub("[LOAD] All parts loaded! playerRoot:", playerRoot.name, "position:", playerRoot.position);
                    resolve({playerRoot, limbs});
                }
            }, null, (scene, msg, exception) => {
                console.error("[LOAD] ERROR loading", partFile, "- Message:", msg, "Exception:", exception);
            });
        });
    });
};
const parseColor = str => {
    if (!str) return new B.Color3(1, 1, 1);
    try {
        const [r, g, b] = str.split(",").map(p => {
            const [n, d] = p.trim().split("/");
            return +n / +d;
        });
        return new B.Color3(r, g, b);
    } catch { return new B.Color3(1, 1, 1); }
};
const fetchAvatar = async () => {
    try {
        const r = await fetch("/avatarData");
        if (r.ok) return await r.json();
        if (DEBUG) console.warn("[AVATAR] No avatar data (server returned", r.status, ") - using defaults");
        return {};
    } catch (e) {
        if (DEBUG) console.warn("[AVATAR] fetch failed, using defaults:", e);
        return {};
    }
};
const setupChat = (adv) => {
    let chatOpen = true;
    const chatPanel = new G.StackPanel();
    Object.assign(chatPanel, {
        width: "300px", height: "400px", background: "rgba(15,15,25,0.85)",
        horizontalAlignment: C.HORIZONTAL_ALIGNMENT_LEFT, verticalAlignment: C.VERTICAL_ALIGNMENT_TOP,
        paddingTop: "10px", paddingLeft: "10px", paddingRight: "10px", isVertical: true, cornerRadius: 10, zIndex: 500
        ,thickness: 0, isPointerBlocker: true
    });
    adv.addControl(chatPanel);
    const chatScrollViewer = new G.ScrollViewer();
    Object.assign(chatScrollViewer, {
        width: "100%", height: "350px",
        thickness: 6, barSize: 16, isVertical: true, isHorizontal: false
    });
    chatPanel.addControl(chatScrollViewer);
    const chatMessages = new G.StackPanel();
        Object.assign(chatMessages, {
            width: "280px", isVertical: true, spacing: 0, adaptHeightToChildren: true
        });
    chatScrollViewer.addControl(chatMessages);
    const addChatMessage = (sender, text) => {
        const msg = new G.TextBlock();
        const senderColorMap = {"You": "#7FFF7F", "[SYSTEM]": "#FFD700", "[SERVER]": "#87CEEB"};
        const senderColor = senderColorMap[sender] || "#FFFFFF";
        Object.assign(msg, {
            text: `${sender}: ${text}`, color: senderColor, fontSize: 12,
            textHorizontalAlignment: C.HORIZONTAL_ALIGNMENT_LEFT, textWrapping: true, 
            width: "260px", height: "auto",
            marginTop: "3px", marginLeft: "5px", marginRight: "5px", resizeToFit: true
        });
        chatMessages.addControl(msg);
        if (chatMessages.children.length > 50) {
            chatMessages.removeControl(chatMessages.children[0]);
        }
        if (chatScrollViewer.verticalBar) {
            chatScrollViewer.verticalBar.value = 1;
        }
    };
    // Input + Send button row
    const inputRow = new G.StackPanel();
    Object.assign(inputRow, {isVertical: false, width: "100%", height: "40px", spacing: 0});
    const chatInput = new G.InputText();
    Object.assign(chatInput, {
        width: "220px", height: "40px", color: "white", background: "rgba(40,40,50,0.9)",
        focusedBackground: "rgba(60,90,140,0.9)", placeholderText: "Type & press...", paddingLeft: "8px",
        fontSize: 13, focusedColor: "white", cornerRadius: 5, horizontalAlignment: C.HORIZONTAL_ALIGNMENT_LEFT
    });
    const sendBtn = G.Button.CreateSimpleButton("sendBtn", "Send");
    Object.assign(sendBtn, {width: "60px", height: "40px", cornerRadius: 5, color: "white", background: "rgba(30,120,30,0.9)", thickness: 0, horizontalAlignment: C.HORIZONTAL_ALIGNMENT_RIGHT});
    const submitChat = () => {
        const raw = chatInput.text || "";
        const txt = raw.trim();
        if (!txt) return;
        try {
            if (txt.startsWith("/")) {
                const lower = txt.toLowerCase();
                if (lower === "/snow off") {
                    STATE.snowEnabled = false;
                    addChatMessage("[SYSTEM]", "Snow disabled");
                } else if (lower === "/snow on") {
                    STATE.snowEnabled = true;
                    addChatMessage("[SYSTEM]", "Snow enabled");
                } else if (lower === "/help") {
                    addChatMessage("[SYSTEM]", "/snow off /snow on");
                } else {
                    addChatMessage("[SYSTEM]", "Unknown command");
                }
            } else {
                addChatMessage("You", txt);
                if (STATE.socket?.connected) {
                    STATE.socket.emit("chatMessage", txt);
                    if (DEBUG) console.lsub("[CLIENT] Sent:", txt);
                }
            }
        } catch (e) {
            console.error("[CHAT] submit error:", e);
        } finally {
            chatInput.text = "";
            if (chatScrollViewer && chatScrollViewer.verticalBar) chatScrollViewer.verticalBar.value = 1;
        }
    };
    chatInput.onKeyboardEventProcessedObservable.add(kb => {
        const evt = kb.event || kb;
        if ((evt.key === "Enter" || evt.keyCode === 13)) {
            submitChat();
        }
    });
    sendBtn.onPointerUpObservable.add(() => submitChat());
    inputRow.addControl(chatInput);
    inputRow.addControl(sendBtn);
    chatPanel.addControl(inputRow);
    // add chat tsubgle button
    const tsubgleBtn = G.Button.CreateSimpleButton("tsubgleChat", chatOpen ? "✕" : "💬");
    Object.assign(tsubgleBtn, {
        width: "50px", height: "50px", cornerRadius: 25, color: "white",
        background: "rgba(80,80,90,0.9)", thickness: 0,
        horizontalAlignment: C.HORIZONTAL_ALIGNMENT_LEFT, verticalAlignment: C.VERTICAL_ALIGNMENT_TOP,
        left: "10px", top: "10px", zIndex: 501
    });
    adv.addControl(tsubgleBtn);
    tsubgleBtn.onPointerUpObservable.add(() => {
        chatOpen = !chatOpen;
        chatPanel.isVisible = chatOpen;
        tsubgleBtn.textBlock.text = chatOpen ? "✕" : "💬";
    });
    // responsive chat: collapse on landscape mode
    const updateChatLayout = () => {
        const w = window.innerWidth;
        const h = window.innerHeight;
        const isLandscape = w > h;
        if (isLandscape) {
            chatPanel.height = "200px";
            chatScrollViewer.height = "150px";
        } else {
            chatPanel.height = "400px";
            chatScrollViewer.height = "350px";
        }
    };
    window.addEventListener("orientationchange", updateChatLayout);
    window.addEventListener("resize", updateChatLayout);
    updateChatLayout(); // call on initial load
    STATE.addChatMessage = addChatMessage;
    return { chatPanel, chatMessages, chatInput, addChatMessage };
};
const createToolbar = (adv, numSlots = 8) => {
    const toolbar = new G.StackPanel();
    Object.assign(toolbar, {
        isVertical: false, height: "60px", width: "400px",
        horizontalAlignment: C.HORIZONTAL_ALIGNMENT_CENTER, verticalAlignment: C.VERTICAL_ALIGNMENT_BOTTOM,
        paddingBottom: "20px", background: "rgba(0,0,0,0.5)", cornerRadius: 12, thickness: 0
    });
    adv.addControl(toolbar);
    const slots = [];
    for (let i = 0; i < numSlots; i++) {
        const slot = new G.Rectangle();
        Object.assign(slot, {
            width: "50px", height: "50px", thickness: 0,
            background: "rgba(100,100,100,0.4)", cornerRadius: 6, paddingRight: "5px"
        });
        toolbar.addControl(slot);
        const icon = new G.Image(`item_${i}`, `https://yoursite/path/items/23.png?item=${151 + i}`);
        Object.assign(icon, {
            width: "90%", height: "90%",
            horizontalAlignment: C.HORIZONTAL_ALIGNMENT_CENTER, verticalAlignment: C.VERTICAL_ALIGNMENT_CENTER
        });
        slot.addControl(icon);
        slots.push({slot, icon, index: i});
    }
    return {toolbar, slots};
};
const loadPlayer = async (scene, engine, canvas, onLoaded) => {
    const ava = await fetchAvatar();
    // use a sphere instead of cube for smoother sky mapping
    const skybox = B.MeshBuilder.CreateSphere("skyBox", {segments: 32, diameter: 1000}, scene);
    const skyMat = new B.StandardMaterial("skyBoxMat", scene);
    const skyTex = new B.Texture("https://yoursite/play/tst/sky.png?r=25125", scene);
    Object.assign(skyMat, {
        backFaceCulling: false, infiniteDistance: true, diffuseColor: new B.Color3(0, 0, 0),
        specularColor: new B.Color3(0, 0, 0), emissiveTexture: skyTex
    });
    // use spherical coordinates for equirectangular-style texture
    skyTex.coordinatesMode = B.Texture.SPHERICAL_MODE;
    skyTex.wrapU = skyTex.wrapV = B.Texture.CLAMP_ADDRESSMODE;
    skybox.material = skyMat;
    // render interior of sphere
    skybox.infiniteDistance = true;
    skybox.isPickable = false;
    Object.assign(scene, {
        fsubMode: B.Scene.FsubMODE_EXP, fsubDensity: 0.002, fsubColor: new B.Color3(0.05, 0.05, 0.1)
    });
    const {playerRoot, limbs} = await loadAvatarModel(scene, ava, "");
    scene.registerBeforeRender(() => {
        skybox.position.copyFrom(playerRoot.position);
        // skybox.rotation.y += 0.00001; // slow
    });
    if (onLoaded) onLoaded({playerRoot, limbs});
    return {playerRoot, limbs};
};
const setupPlayerController = (playerRoot, scene, engine, canvas, limbs) => {
    Object.assign(playerRoot, {
        position: new B.Vector3(0, 15, 0),
        checkCollisions: true,
        ellipsoid: new B.Vector3(0.5, 0.9, 0.5),
        ellipsoidOffset: new B.Vector3(0, 0.9, 0)
    });
    const camera = new B.ArcRotateCamera("ArcCamera", 0, Math.PI / 2.5, 10, playerRoot.position.clone(), scene);
    camera.attachControl(canvas, true);
    STATE.cc1 = new CharacterController(playerRoot, camera, scene);
    STATE.cc1.setWalkSpeed(2.15);
    STATE.cc1.setFaceForward(false);
    STATE.cc1.setCameraTarget(new B.Vector3(0, 1.5, 0));
    STATE.cc1.setCameraElasticity(false);
    STATE.cc1.setStepOffset(0.5);
    scene.executeWhenReady(() => setTimeout(() => STATE.cc1.start(), 500));
    // intercept jump to notify other players
    if (STATE.cc1 && STATE.socket) {
        const origJump = STATE.cc1.jump.bind(STATE.cc1);
        STATE.cc1.jump = () => {
            origJump();
            if (STATE.socket.connected) STATE.socket.emit("playerJump");
        };
    }
    const inputMap = {};
    let shiftLock = false;
    const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent) || navigator.maxTouchPoints > 0;
    if (isMobile) {
        const thumbContainer = new G.Ellipse();
        Object.assign(thumbContainer, {
            width: "120px", height: "120px", thickness: 2, color: "rgba(150,150,200,0.4)",
            background: "rgba(255,255,255,0.15)", horizontalAlignment: C.HORIZONTAL_ALIGNMENT_LEFT,
            verticalAlignment: C.VERTICAL_ALIGNMENT_BOTTOM, left: "50px", top: "-50px", isPointerBlocker: true
        });
        STATE.adv.addControl(thumbContainer);
        const thumbInner = new G.Ellipse();
        Object.assign(thumbInner, {width: "50px", height: "50px", background: "rgba(100,150,255,0.8)", isPointerBlocker: true, thickness: 0});
        thumbContainer.addControl(thumbInner);
        const jumpBtn = G.Button.CreateSimpleButton("jumpBtn", "JUMP");
        Object.assign(jumpBtn, {
            width: "100px", height: "100px", color: "white", background: "rgba(200,100,100,0.6)", cornerRadius: 50,
            horizontalAlignment: C.HORIZONTAL_ALIGNMENT_RIGHT, verticalAlignment: C.VERTICAL_ALIGNMENT_BOTTOM,
            left: "-50px", top: "-50px", isPointerBlocker: true, thickness: 0
        });
        jumpBtn.onPointerDownObservable.add(() => STATE.cc1?.jump());
        STATE.adv.addControl(jumpBtn);
        let joyX = 0, joyY = 0, isJoyActive = false;
        const stopJoystick = () => {
            isJoyActive = false;
            joyX = joyY = 0;
            thumbInner.left = thumbInner.top = 0;
        };
        thumbContainer.onPointerDownObservable.add(() => isJoyActive = true);
        thumbContainer.onPointerMoveObservable.add(coords => {
            if (!isJoyActive) return;
            let x = coords.x - (thumbContainer.centerX + thumbContainer.leftInPixels);
            let y = coords.y - (thumbContainer.centerY + thumbContainer.topInPixels);
            const max = 40;
            const dist = Math.hypot(x, y);
            if (dist > max) {
                x = (x / dist) * max;
                y = (y / dist) * max;
            }
            thumbInner.left = x;
            thumbInner.top = y;
            joyX = x / max;
            joyY = -y / max;
        });
        thumbContainer.onPointerUpObservable.add(stopJoystick);
        scene.onPointerUp = stopJoystick;
        // ensure joystick is above all other UI controls
        thumbContainer.zIndex = 600;
        jumpBtn.zIndex = 600;
        var isMobileJoy = {joyX: () => joyX, joyY: () => joyY, isJoyActive: () => isJoyActive};
    }
    scene.actionManager = new B.ActionManager(scene);
    scene.actionManager.registerAction(
        new B.ExecuteCodeAction(B.ActionManager.OnKeyDownTrigger, evt => {
            const key = evt.sourceEvent.key.toLowerCase();
            inputMap[key] = true;
            if (key === "shift") {
                // tsubgle shift-lock mode (prevents auto-turning)
                shiftLock = !shiftLock;
                STATE.cc1.setTurningOff(shiftLock);
                // also apply a slight speed boost while shift-lock is active (works on ground or in air)
                STATE.cc1.setWalkSpeed(shiftLock ? 3.15 : 2.15);
            }
            if (key === "control" || key === "meta") STATE.cc1.setWalkSpeed(3.15);
        })
    );
    scene.actionManager.registerAction(
        new B.ExecuteCodeAction(B.ActionManager.OnKeyUpTrigger, evt => {
            const key = evt.sourceEvent.key.toLowerCase();
            inputMap[key] = false;
            if (key === "control" || key === "meta") STATE.cc1.setWalkSpeed(2.15);
            if (key === "shift") {
                // when shift released, restore normal walk speed but keep shiftLock state
                STATE.cc1.setWalkSpeed(2.15);
            }
        })
    );
    const getForwardRight = () => {
        const forward = camera.getForwardRay().direction;
        forward.y = 0;
        forward.normalize();
        const right = B.Vector3.Cross(B.Axis.Y, forward).normalize();
        return {forward, right};
    };
    scene.registerBeforeRender(() => {
        let moveX = 0, moveZ = 0;
        if (isMobile && isMobileJoy.isJoyActive()) {
            moveX = isMobileJoy.joyX();
            moveZ = isMobileJoy.joyY();
        }
        if (inputMap["w"] || inputMap["arrowup"]) moveZ += 1;
        if (inputMap["s"] || inputMap["arrowdown"]) moveZ -= 1;
        if (inputMap["a"] || inputMap["arrowleft"]) moveX -= 1;
        if (inputMap["d"] || inputMap["arrowright"]) moveX += 1;
        if (moveX !== 0 || moveZ !== 0) {
            const {forward, right} = getForwardRight();
            const worldMove = forward.scale(moveZ).add(right.scale(moveX)).normalize().scale(0.15);
            playerRoot.moveWithCollisions(worldMove);
            if (!shiftLock) {
                playerRoot.rotation.y = Math.atan2(worldMove.x, worldMove.z);
            } else {
                // shift-lock keeps orientation fixed; optionally sync with camera yaw
                if (camera) {
                    // arc rotate camera alpha is opposite of player facing
                    playerRoot.rotation.y = camera.alpha + Math.PI;
                }
            }
        }
        // bug: due to the bones for each arms and conditions lsubic only one swings properly arm with hand and the other one (arm) only the hand swings (perhaps a model issue? with skinweights?)
        ["leftArm", "rightArm", "leftLeg", "rightLeg"].forEach(l => {
            if (limbs[l]) {
                if (moveX !== 0 || moveZ !== 0) {
                    const swing = Math.sin(performance.now() * 0.005) * 0.5;
                    limbs[l].rotation.x = (l.includes("left") && l.includes("Arm")) || (l.includes("right") && l.includes("Leg")) ? swing : -swing;
                } else {
                    limbs[l].rotation.x = 0;
                }
            }
        });
        if (playerRoot.position.y < -15) playerRoot.position.set(0, 15, 0);
        if (STATE.multiplayerEnabled && STATE.socket) {
            const now = performance.now();
            if (now - STATE.lastEmit > 100) {
                // convert Babylon Vector3 to plain object for network
                const pos = playerRoot.position;
                STATE.socket.emit("playerMove", {position: {x: pos.x, y: pos.y, z: pos.z}, rotation: {y: playerRoot.rotation.y}});
                STATE.lastEmit = now;
            }
        }
    });
    engine.runRenderLoop(() => scene.render());
    canvas.focus();
};
const createOtherPlayer = async (data, scene) => {
    if (STATE.players[data.id]) {
        if (DEBUG) console.lsub("[NET] Player", data.id, "already exists, skipping");
        return;
    }
    // Validate required data - position must have x, y, z
    if (!data.position || typeof data.position.x !== "number") {
        console.error("[NET] Invalid position data for player", data.id, "position:", data.position, "full data:", data);
        return;
    }
    if (DEBUG) console.lsub("[NET] Creating other player", data.id, data);
    // first load the avatar geometry so we don't have a floating face glitch
    if (DEBUG) console.lsub("[AVATAR] Starting load for", data.id, "avatar data:", data.avatar);
    const {playerRoot, limbs} = await loadAvatarModel(scene, data.avatar || {}, "_" + data.id);
    if (DEBUG) console.lsub("[AVATAR] Loaded for", data.id, "limbs:", Object.keys(limbs));
    playerRoot.name = "other_" + data.id;
    if (DEBUG) console.lsub("[AVATAR] Setting position for", data.id, "to:", data.position);
    // Ensure position is above ground (minimum Y = 0.5 to prevent underground clipping)
    const safeY = Math.max(data.position.y, 0.5);
    playerRoot.position.set(data.position.x, safeY, data.position.z);
    playerRoot.visibility = 1;
    playerRoot.rotation.y = data.rotation.y;
    playerRoot.targetPos = playerRoot.position.clone();
    playerRoot.targetRotY = playerRoot.rotation.y;
    if (DEBUG) console.lsub("[AVATAR] After set - position:", playerRoot.position, "visible:", playerRoot.visibility);
    STATE.players[data.id] = {root: playerRoot, limbs};
    if (DEBUG) console.lsub("[AVATAR] Added to STATE.players, position:", playerRoot.position);
    const nametag = new G.Rectangle();
    Object.assign(nametag, {width: "200px", height: "40px", cornerRadius: 5, color: "white", thickness: 0, background: "rgba(0,0,0,0.5)"});
    const label = new G.TextBlock();
    Object.assign(label, {text: data.name || "Player", color: "white", fontSize: 18});
    nametag.addControl(label);
    // add to separate nametag texture layer that's truly root-level
    STATE.nametagContainer.addControl(nametag);
    // try to link to head mesh if available so tag stays locked to the head regardless of zoom
    let linkMesh = playerRoot;
    if (data.avatar && data.avatar.headMesh) {
        linkMesh = data.avatar.headMesh;
    } else {
        // fallback: search for a child mesh whose name contains "head"
        const head = playerRoot.getChildMeshes().find(m => /head/i.test(m.name));
        if (head) linkMesh = head;
    }
    nametag.linkWithMesh(linkMesh);
    // small upward offset in case mesh origin is inside head; will scale with zoom automatically
    nametag.linkOffsetY = -30;
    STATE.players[data.id].nametag = nametag;
    // apply any movement that occurred while the model was loading
    const pending = STATE.pendingMoves[data.id];
    if (pending) {
        if (DEBUG) console.lsub("[AVATAR] Applying pending move for", data.id);
        playerRoot.position.set(pending.position.x, pending.position.y, pending.position.z);
        playerRoot.targetPos = playerRoot.position.clone();
        playerRoot.rotation.y = pending.rotation.y;
        playerRoot.targetRotY = pending.rotation.y;
        delete STATE.pendingMoves[data.id];
    }
    if (DEBUG) console.lsub("[AVATAR] Finished loading", data.id);
};
const createGround = (scene, groundMaterial) => {
    B.MeshBuilder.CreateGroundFromHeightMap("ground", "ground/ground_heightMap.png", {
        width: 128, height: 128, minHeight: 0, maxHeight: 10, subdivisions: 32,
        onReady: grnd => {grnd.material = groundMaterial; grnd.checkCollisions = true; grnd.isPickable = true; grnd.freezeWorldMatrix();}
    }, scene);
};
const createGroundMaterial = scene => {
    const mat = new B.StandardMaterial("groundMat", scene);
    mat.diffuseTexture = new B.Texture("ground/ground.jpg", scene);
    mat.diffuseTexture.uScale = mat.diffuseTexture.vScale = 4;
    mat.bumpTexture = new B.Texture("ground/ground-normal.png", scene);
    mat.bumpTexture.uScale = mat.bumpTexture.vScale = 12;
mat.diffuseColor = new B.Color3(0.7, 0.8, 0.6); // light green-brown
mat.specularColor = new B.Color3(0, 0, 0);
    return mat;
};
const createObby = scene => {
    const start = new B.Vector3(5, 1, 0);
    const cubeSize = 2, stepHeight = 1.5, stepCount = 10;
    for (let i = 0; i < stepCount; i++) {
        const width = i === stepCount - 1 ? 6 : cubeSize;
        const depth = i === stepCount - 1 ? 6 : cubeSize;
        const cube = B.MeshBuilder.CreateBox("step_" + i, {width, height: stepHeight, depth}, scene);
        cube.position = new B.Vector3(start.x + i * (cubeSize + 0.5), start.y + i * stepHeight + (stepHeight / 2 - stepHeight / 2), start.z);
        const mat = new B.StandardMaterial("stepMat_" + i, scene);
        mat.diffuseColor = new B.Color3(Math.random(), Math.random(), Math.random());
        cube.material = mat;
        cube.checkCollisions = true;
        cube.isPickable = false;
    }
    for (let i = 0; i < 8; i++) {
        const sphere = B.MeshBuilder.CreateSphere("sphere_" + i, {diameter: 2}, scene);
        sphere.position = new B.Vector3(start.x + Math.random() * 15, start.y + 2 + Math.random() * stepCount * stepHeight, start.z + (Math.random() > 0.5 ? 4 : -4));
        const mat = new B.StandardMaterial("sphereMat_" + i, scene);
        mat.diffuseColor = new B.Color3(Math.random(), Math.random(), Math.random());
        sphere.material = mat;
        sphere.checkCollisions = true;
        sphere.isPickable = false;
    }
    for (let i = 0; i < 5; i++) {
        const width = 3 + Math.random() * 3, depth = 3 + Math.random() * 3;
        const plat = B.MeshBuilder.CreateBox("plat_" + i, {width, height: 0.5, depth}, scene);
        plat.position = new B.Vector3(start.x + Math.random() * 18, start.y + 1 + Math.random() * stepCount * stepHeight, start.z + (Math.random() * 10 - 5));
        const mat = new B.StandardMaterial("platMat_" + i, scene);
        mat.diffuseColor = new B.Color3(Math.random(), Math.random(), Math.random());
        plat.material = mat;
        plat.checkCollisions = true;
        plat.isPickable = false;
    }
    for (let i = 0; i < 3; i++) {
        const rotCube = B.MeshBuilder.CreateBox("rotCube_" + i, {size: 1.5}, scene);
        rotCube.position = new B.Vector3(start.x + Math.random() * 15, start.y + 2 + Math.random() * stepCount * stepHeight, start.z + (Math.random() * 8 - 4));
        const mat = new B.StandardMaterial("rotCubeMat_" + i, scene);
        mat.diffuseColor = new B.Color3(Math.random(), Math.random(), Math.random());
        rotCube.material = mat;
        rotCube.checkCollisions = true;
        rotCube.isPickable = false;
        scene.registerBeforeRender(() => {
            rotCube.rotation.y += 0.02 + Math.random() * 0.01;
            rotCube.rotation.x += 0.01;
        });
    }
    for (let i = 0; i < 5; i++) {
        const coin = B.MeshBuilder.CreateCylinder("coin_" + i, {diameter: 0.5, height: 0.2}, scene);
        coin.position = new B.Vector3(start.x + Math.random() * 15, start.y + 3 + Math.random() * stepCount * stepHeight, start.z + (Math.random() * 10 - 5));
        const mat = new B.StandardMaterial("coinMat_" + i, scene);
        mat.diffuseColor = new B.Color3(1, 0.84, 0);
        coin.material = mat;
        coin.checkCollisions = false;
        coin.isPickable = true;
        scene.registerBeforeRender(() => {
            coin.position.y += Math.sin(performance.now() * 0.002 + i) * 0.003;
        });
    }
    // spawn some candies alongside coins (will clone template when ready)
    for (let i = 0; i < 5; i++) {
        const pos = new B.Vector3(
            start.x + Math.random() * 15,
            start.y + 3 + Math.random() * stepCount * stepHeight,
            start.z + (Math.random() * 10 - 5)
        );
        spawnCandyAt(pos);
    }
};
const tryMultiplayer = cb => {
    try {
        STATE.socket = io("/", {timeout: 3000});
        STATE.socket.on("connect", () => {
            STATE.multiplayerEnabled = true;
            if (DEBUG) console.lsub("[NET] Connected:", STATE.socket.id);
            cb();
        });
        STATE.socket.on("connect_error", e => {
            STATE.multiplayerEnabled = false;
            console.warn("[UnNET] Singleplayer", e);
            cb();
        });
        STATE.socket.on("disconnect", r => console.warn("❌ Disconnected:", r));
    } catch (e) {
        STATE.multiplayerEnabled = false;
        console.warn("[NETErr] Multiplayer failed:", e);
        cb();
    }
};
const initMultiplayer = (playerRoot, scene, limbs) => {
    if (!STATE.socket || !STATE.addChatMessage) return;
    STATE.socket.on("currentPlayers", players => {
        if (DEBUG) console.lsub("[NET] currentPlayers event, my ID:", STATE.socket.id, "all players:", Object.keys(players));
        for (const id in players) {
            if (DEBUG) console.lsub("[NET]   Checking player", id, "is it me?", id === STATE.socket.id, "player data:", players[id]);
            // IMPORTANT: Skip yourself - only load OTHER players
            if (id === STATE.socket.id) {
                if (DEBUG) console.lsub("[NET]   ✓ Skipping myself (local player already loaded)");
                continue;
            }
            console.lsub("[NET] Loading existing player", id, "position:", players[id].position);
            createOtherPlayer(players[id], scene);
        }
    });
    STATE.socket.on("newPlayer", p => {
        console.lsub("[NET] New player event - id:", p.id, "is it me?", p.id === STATE.socket.id);
        // Skip if this is somehow yourself (shouldn't happen from newPlayer broadcast)
        if (p.id === STATE.socket.id) {
            console.lsub("[NET] ✓ Ignoring newPlayer for myself");
            return;
        }
        STATE.addChatMessage("[SERVER]", `${p.name} joined`);
        console.lsub("[NET] Creating new player", p.id, "position:", p.position);
        createOtherPlayer(p, scene);
    });
    STATE.socket.on("chatMessage", d => {
        console.lsub("[RECEIVED]", d);
        console.lsub("[DEBUG] STATE.addChatMessage exists?", !!STATE.addChatMessage, typeof STATE.addChatMessage);
        if (STATE.addChatMessage) {
            STATE.addChatMessage(d.sender, d.text);
        } else {
            console.error("[ERROR] STATE.addChatMessage is not defined!");
        }
    });
    STATE.socket.on("playerMoved", p => {
        // movement received for remote player; buffer if not yet created
        let o = STATE.players[p.id];
        if (!o) {
            STATE.pendingMoves[p.id] = {position: p.position, rotation: p.rotation};
            return;
        }
        o.root.targetPos = new B.Vector3(p.position.x, p.position.y, p.position.z);
        o.root.targetRotY = p.rotation.y;
    });
    STATE.socket.on("playerJump", id => {
        const o = STATE.players[id];
        if (!o) return;
        // mark the remote player as jumping so we can adjust their animation
        o.isJumping = true;
        // don't modify the targetPos here; vertical movement will arrive naturally
        // with the next playerMoved update.  we just want to clear swing animation.
        if (o.limbs) {
            ["leftArm","rightArm","leftLeg","rightLeg"].forEach(l => {
                if (o.limbs[l]) {
                    o.limbs[l].rotation.x = 0;
                }
            });
        }
        setTimeout(() => { o.isJumping = false; }, 500);
    });
    STATE.socket.on("serverCorrection", d => {
        playerRoot.position.set(d.position.x, d.position.y, d.position.z);
    });
    STATE.socket.on("playerDisconnected", id => {
        const o = STATE.players[id];
        if (!o) return;
        const name = o.root.name;
        STATE.addChatMessage("[SERVER]", `${name.replace("other_", "")} left`);
        o.root.dispose();
        ["nametag"].forEach(k => {
            if (o[k]) {
                STATE.adv?.removeControl(o[k]);
                o[k].dispose();
            }
        });
        delete STATE.players[id];
        delete STATE.pendingMoves[id];
    });
    scene.registerBeforeRender(() => {
        Object.values(STATE.players).forEach(o => {
            if (!o.root.targetPos) return;
            // interpolate (use a slightly larger step when jumping or moving vertically so it feels snappier)
            const lerpFactor = (o.isJumping || Math.abs(o.root.targetPos.y - o.root.position.y) > 0.5) ? 0.3 : 0.15;
            o.root.position = B.Vector3.Lerp(o.root.position, o.root.targetPos, lerpFactor);
            o.root.rotation.y = B.Scalar.LerpAngle(o.root.rotation.y, o.root.targetRotY, 0.15);
            // clamp to ground beneath but allow upward jumps
            const ray = new B.Ray(o.root.position.add(new B.Vector3(0, 5, 0)), B.Vector3.Down(), 10);
            const pick = STATE.scene.pickWithRay(ray, mesh => mesh.name.startsWith("ground"));
            if (pick && pick.hit && pick.pickedPoint) {
                const groundY = pick.pickedPoint.y + 0.1;
                // only clamp when both current and target are near ground (not jumping)
                if (o.root.position.y <= groundY + 0.2 && o.root.targetPos.y <= groundY + 0.2) {
                    o.root.position.y = groundY;
                }
                // if we just landed, clear jumping state early
                if (o.isJumping && o.root.position.y <= groundY + 0.2) {
                    o.isJumping = false;
                }
            }
            // animate limbs similarly to local player, and add idle bobbing
            if (o.limbs) {
                const diff = o.root.targetPos.subtract(o.root.position);
                // only consider horizontal motion for walking
                const horiz = new B.Vector3(diff.x, 0, diff.z);
                const isMoving = horiz.length() > 0.1 && !o.isJumping;
                ["leftArm","rightArm","leftLeg","rightLeg"].forEach(l => {
                    if (!o.limbs[l]) return;
                    if (isMoving) {
                        const swing = Math.sin(performance.now() * 0.005) * 0.5;
                        o.limbs[l].rotation.x = (l.includes("left") && l.includes("Arm")) || (l.includes("right") && l.includes("Leg")) ? swing : -swing;
                    } else {
                        o.limbs[l].rotation.x = 0;
                    }
                });
                // idle bobbing when not moving and not jumping
                if (!isMoving && !o.isJumping) {
                    const bob = Math.sin(performance.now() * 0.002) * 0.02;
                    o.root.position.y += bob;
                }
            }
        });
    });
};
window.onload = () => {
    STATE.canvas = document.querySelector("#renderCanvas");
    const engine = new B.Engine(STATE.canvas, true);
    const scene = new B.Scene(engine);
    STATE.scene = scene;
    // setup snow effect (default enabled)
    createSnow(scene);
    // preload candy model; clones will be created later in createObby
    B.SceneLoader.ImportMesh("", "https://yoursite/path/assets/176.obj?item=151", "", scene, meshes => {
        console.lsub("[CANDY] import returned", meshes.length, "meshes");
        // merge into single template for easy cloning
        candyTemplate = B.Mesh.MergeMeshes(meshes, true, true, undefined, false, true);
        if (candyTemplate) {
            candyTemplate.isVisible = false;
            candyTemplate.checkCollisions = false;
            candyTemplate.isPickable = false;
            // apply texture so clones inherit correct look
            const candyMat = new B.StandardMaterial("candyMat", scene);
            candyMat.diffuseTexture = new B.Texture("https://yoursite/path/assets/176.png?item=151", scene);
            candyTemplate.material = candyMat;
            console.lsub("[CANDY] template ready");
        }
        // spawn any candies that were queued before load
        pendingCandySpawns.forEach(p => spawnCandyAt(p));
        pendingCandySpawns = [];
    });
    Object.assign(scene, {
        collisionsEnabled: true, gravity: new B.Vector3(0, -0.3, 0),
        clearColor: new B.Color3(0.75, 0.75, 0.75), ambientColor: new B.Color3(1, 1, 1)
    });
    STATE.adv = G.AdvancedDynamicTexture.CreateFullscreenUI("UI_main");
    STATE.adv.useInvalidateRectOptimization = false;
    // add on-screen FPS counter
    const fpsLabel = new G.TextBlock();
Object.assign(fpsLabel, {
    text: "FPS: ?",
    color: "white",
    fontSize: 16,
    width: "120px",
    height: "40px",
    horizontalAlignment: C.HORIZONTAL_ALIGNMENT_RIGHT,
    verticalAlignment: C.VERTICAL_ALIGNMENT_TOP,
    paddingRight: "10px",
    paddingTop: "10px",
    zIndex: 1
});
    STATE.adv.addControl(fpsLabel);
    scene.registerBeforeRender(() => {
        const f = Math.round(scene.getEngine().getFps());
        fpsLabel.text = `FPS: ${f}`;
    });
    // create separate texture for nametags so they can link to meshes (root level only)
    STATE.nametagContainer = G.AdvancedDynamicTexture.CreateFullscreenUI("UI_nametags");
    // adjust nametag offset to compensate for camera zoom/distance
    scene.registerBeforeRender(() => {
        const cam = scene.activeCamera;
        if (!cam) return;
        for (const id in STATE.players) {
            const entry = STATE.players[id];
            const tag = entry.nametag;
            if (!tag || !entry.root) continue;
            // calculate distance from camera to the player's head (approx)
            const mesh = entry.root;
            const pos = mesh.getBoundingInfo().boundingBox.centerWorld;
            const dist = BABYLON.Vector3.Distance(pos, cam.position);
            // the scaling factor (0.2) was chosen empirically; adjust as needed
            tag.linkOffsetY = -dist * 0.2;
        }
    });
    const {chatPanel, addChatMessage} = setupChat(STATE.adv);
    createToolbar(STATE.adv);
    new B.HemisphericLight("light1", new B.Vector3(0, 1, 0), scene).intensity = 1;
    createGround(scene, createGroundMaterial(scene));
    createObby(scene);
    loadPlayer(scene, engine, STATE.canvas, data => {
        STATE.playerLimbs = data.limbs;
        STATE.playerRoot = data.playerRoot;
        tryMultiplayer(() => {
            setupPlayerController(data.playerRoot, scene, engine, STATE.canvas, data.limbs);
            if (STATE.multiplayerEnabled) initMultiplayer(data.playerRoot, scene, data.limbs);
        });
    });
    window.addEventListener("keydown", e => {
        const key = Number(e.key);
        if (key >= 1 && key <= 8 && STATE.playerLimbs && STATE.scene) {
            const toolSlots = Array.from({length: 8}, (_, i) => ({img: `${23 + i}.png`, obj: `${23 + i}.obj`}));
            const {obj} = toolSlots[key - 1];
            B.SceneLoader.ImportMesh("", ASSET_BASE, obj, STATE.scene, meshes => {
                if (meshes.length && STATE.playerLimbs.rightArm) {
                    const tool = B.Mesh.MergeMeshes(meshes, true, true, undefined, false, true);
                    if (tool) {
                        tool.parent = STATE.playerLimbs.rightArm;
                        tool.position = B.Vector3.Zero();
                        tool.scaling.set(1, 1, 1);
                        tool.rotation.x = Math.PI / 2;
                        tool.isPickable = false;
                        tool.bakeCurrentTransformIntoVertices();
                    }
                }
            });
        }
    });
    window.addEventListener("resize", () => engine.resize());
};
</script>
</head>
<body>
<audio src="https://sub.yoursite/sounds/4.mp3" autoplay></audio>
<canvas id="renderCanvas" tabindex="-1"></canvas>
</body>
</html>
