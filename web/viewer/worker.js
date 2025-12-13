// Worker for background getSrc processing
let loadedCache = {};
let pendingRequests = new Set();
let activeRequests = new Map();
let corsFailedUrls = new Set();
let decryptionStatus = new Map();
let usingRapiServe = "";
let passphrase = "";
let maxTries = 3;

// Mock rcloneCrypt - replace with full library code via importScripts or inline
self.rcloneCrypt = {
  encryptPath: async (path, pass) => {
    // Mock - implement full rcloneCrypt.encryptPath
    return btoa(path);
  },
  decrypt: async (path, pass) => {
    // Mock - implement full rcloneCrypt.decrypt
    const response = await self.fetch(path);
    const data = await response.arrayBuffer();
    return new Uint8Array(data);
  },
  render: (data, ext, a, b) => {
    // Mock - implement full rcloneCrypt.render
    return URL.createObjectURL(new Blob([data]));
  },
  type: (ext) => {
    const types = {
      jpg: "image/jpeg",
      jpeg: "image/jpeg",
      gif: "image/gif",
      png: "image/png",
      mp4: "video/mp4",
      webm: "video/webm",
    };
    return types[ext] || "application/octet-stream";
  },
};

self.onmessage = function onWorkerMessage(e) {
  const data = e.data;
  const type = data.type;

  if (type === "init") {
    passphrase = data.passphrase || "";
    usingRapiServe = data.usingRapiServe || "";
    self.postMessage({ type: "ready" });
    return;
  }

  if (type === "getSrc") {
    const resource = data.resource;
    const currentTries = data.currentTries || 0;
    getSrcWorker(resource, currentTries)
      .then((result) => {
        self.postMessage({
          type: "result",
          resource: resource,
          result: result,
        });
      })
      .catch((error) => {
        self.postMessage({
          type: "error",
          resource: resource,
          error: error.message,
        });
      });
    return;
  }

  if (type === "cancel") {
    if (data.all) {
      activeRequests.forEach((c) => {
        if (c.abort) c.abort();
      });
      activeRequests.clear();
      pendingRequests.clear();
      return;
    }
    const controller = activeRequests.get(data.resource);
    if (controller && controller.abort) {
      controller.abort();
      activeRequests.delete(data.resource);
      pendingRequests.delete(data.resource);
    }
    return;
  }

  if (type === "flushCache") {
    loadedCache = {};
    pendingRequests.clear();
    activeRequests.clear();
    corsFailedUrls.clear();
    decryptionStatus.clear();
    return;
  }
};

async function getSrcWorker(resource, currentTries = 0) {
  return new Promise((resolve, reject) => {
    (async () => {
      try {
        if (loadedCache[resource]) {
          return resolve(loadedCache[resource]);
        }
        if (resource.includes("blob:")) {
          return resolve(resource);
        }
        if (pendingRequests.has(resource)) {
          const waitForExisting = () =>
            new Promise((r) => {
              const interval = setInterval(() => {
                if (!pendingRequests.has(resource)) {
                  clearInterval(interval);
                  resolve(loadedCache[resource] || resource);
                }
              }, 100);
              setTimeout(() => {
                clearInterval(interval);
                resolve(resource);
              }, 3000);
            });
          return waitForExisting();
        }
        const urlBase = resource.split("?")[0];
        if (corsFailedUrls.has(urlBase)) {
          return resolve(resource);
        }
        const resourcePath = resource.split("/").slice(0, -1).join("/");
        const serverType = usingRapiServe.length ? "rapiServe" : "direct";
        const decryptionKey = serverType + ":" + resourcePath;
        const status = decryptionStatus.get(decryptionKey);
        if (status === false) {
          let targetSrc = resource;
          if (usingRapiServe.length) {
            if (!resource.startsWith("http")) {
              targetSrc = usingRapiServe + "/" + resource;
              if (resource.startsWith(usingRapiServe + "/")) {
                targetSrc = resource;
              }
            }
          }
          loadedCache[resource] = targetSrc;
          return resolve(targetSrc);
        }
        if (status === true) {
          try {
            const encryptedPath = await rcloneCrypt.encryptPath(
              resource,
              passphrase,
            );
            const decryptedData = await rcloneCrypt.decrypt(
              "crypt/" + encryptedPath,
              passphrase,
            );
            const decrypted = rcloneCrypt.render(
              decryptedData,
              "",
              false,
              false,
            );
            loadedCache[resource] = decrypted;
            return resolve(decrypted);
          } catch (e) {
            console.warn("Decryption failed:", e);
          }
        }
        pendingRequests.add(resource);

        let controller = AbortController
          ? new AbortController()
          : {
              signal: { aborted: false },
              abort: function () {
                this.signal.aborted = true;
              },
            };
        activeRequests.set(resource, controller);

        const cleanup = () => {
          activeRequests.delete(resource);
          pendingRequests.delete(resource);
        };

        try {
          if (usingRapiServe.length) {
            let targetSrc = resource.startsWith("http")
              ? resource
              : usingRapiServe + "/" + resource;
            if (resource.startsWith(usingRapiServe + "/")) targetSrc = resource;
            decryptionStatus.set("rapiServe:" + resourcePath, false);

            const response = await self.fetch(targetSrc, {
              headers: { range: "bytes=0-1" },
              signal: controller.signal,
            });

            if (!response.ok) throw new Error("HTTP " + response.status);

            cleanup();
            loadedCache[resource] = targetSrc;
            return targetSrc;
          }

          // HEAD for decryption check
          const headResponse = await self.fetch(resource, {
            method: "HEAD",
            signal: controller.signal,
          });

          if (headResponse.ok) {
            decryptionStatus.set(decryptionKey, false);
            cleanup();
            return resource;
          }

          decryptionStatus.set(decryptionKey, true);

          const encryptedPath = await rcloneCrypt.encryptPath(
            resource,
            passphrase,
          );
          const decryptedData = await rcloneCrypt.decrypt(
            "crypt/" + encryptedPath,
            passphrase,
          );
          const decrypted = rcloneCrypt.render(decryptedData, "", false, false);

          cleanup();
          loadedCache[resource] = decrypted;
          return decrypted;
        } catch (error) {
          cleanup();
          if (error.name === "AbortError") {
            throw new Error("Request cancelled or timed out");
          }
          if (
            error.message.includes("CORS") ||
            error.message.includes("access control")
          ) {
            console.warn("CORS error for " + resource);
            corsFailedUrls.add(urlBase);
            return resource;
          }
          throw error;
        }
      } catch (error) {
        if (currentTries < maxTries) {
          const delay = Math.pow(2, currentTries) * 1000;
          await new Promise((r) => setTimeout(r, delay));
          return getSrcWorker(resource, currentTries + 1);
        }
        return resource;
      }
    })()
      .then(resolve)
      .catch(reject);
  });
}
