// Simplified medcrypt with clean queue system
let medcrypt = {
  // Simple queue - array for easy prepend/append
  queue: [],
  processing: false,

  // Optimization flags
  usesPrFa: false, // true when /pr/ or /fa/ succeeds
  skipPrFa: false, // true when no-prefix succeeds
  useCrypt: false, // true when any /crypt/ path succeeds

  // Blob URL management (max 48)
  blobCache: new Map(), // resource -> blob URL
  maxBlobs: 48,

  // Helper: Check if resource is image/video
  isMediaFile(resource) {
    return /\.(jpg|jpeg|png|gif|webp|bmp|svg|mp4|webm|ogg|mov)$/i.test(
      resource,
    );
  },

  // Helper: Check if viewport vicinity
  isNearViewport(element) {
    if (!element) return true; // If no element, allow
    const rect = element.getBoundingClientRect();
    const viewportHeight = window.innerHeight;
    // Within 1.5 viewports above/below
    return (
      rect.top < viewportHeight * 2.5 && rect.bottom > -(viewportHeight * 2.5)
    );
  },

  // Add to queue
  getSrc(resource, priority = "low") {
    return new Promise((resolve, reject) => {
      const task = { resource, resolve, reject, priority };

      if (priority === "high") {
        this.queue.unshift(task); // Prepend high priority
      } else {
        this.queue.push(task); // Append low priority
      }

      if (!this.processing) {
        this.processQueue();
      }
    });
  },

  // Process queue
  async processQueue() {
    if (this.processing || this.queue.length === 0) return;
    this.processing = true;

    while (this.queue.length > 0) {
      const task = this.queue.shift(); // Pop from top

      try {
        const result = await this.fetchResource(task.resource);
        task.resolve(result);
      } catch (error) {
        task.reject(error);
      }
    }

    this.processing = false;
  },

  // Fetch resource with path variations
  async fetchResource(resource) {
    const isFlashOrVr =
      resource.startsWith("flash/") || resource.startsWith("vr/");
    const isMedia = this.isMediaFile(resource);

    // Generate path variations
    const paths = [];

    if (isMedia && !isFlashOrVr) {
      // Try unencrypted paths first (unless we know to use crypt)
      if (!this.useCrypt) {
        if (!this.skipPrFa) {
          paths.push("pr/" + resource);
          paths.push("fa/" + resource);
        }
        if (!this.usesPrFa) {
          paths.push(resource); // no prefix
        }
      }

      // Try encrypted paths
      paths.push("crypt/pr/" + resource);
      paths.push("crypt/fa/" + resource);
      paths.push("crypt/" + resource);
    } else if (isFlashOrVr) {
      // Flash/VR paths
      paths.push(resource);
      paths.push("crypt/" + resource);
    } else {
      // Non-media files - just try as-is
      paths.push(resource);
    }

    // Try each path variation (max 3 attempts per path)
    for (const path of paths) {
      for (let attempt = 1; attempt <= 3; attempt++) {
        try {
          const url = usingRapiServe.length
            ? `${usingRapiServe}/${path}`
            : getBasePath() + path;

          // Check viewport before fetch
          if (
            this.isNearViewport(
              document.querySelector(`[data-src="${resource}"]`),
            )
          ) {
            const response = await fetch(url, {
              method: "HEAD",
              signal: AbortSignal.timeout(10000),
            });

            if (response.ok) {
              // Success! Update flags
              if (path.startsWith("crypt/")) {
                this.useCrypt = true;
              } else if (path.startsWith("pr/") || path.startsWith("fa/")) {
                this.usesPrFa = true;
              } else if (path === resource) {
                this.skipPrFa = true;
              }

              // Get blob URL for media files
              if (isMedia && window.location.protocol === "https:") {
                return await this.getBlobUrl(url, resource);
              }

              return url;
            }
          } else {
            throw new Error("Outside viewport");
          }
        } catch (error) {
          if (error.message === "Outside viewport") throw error;
          // Retry on failure (timeout, network error, etc.)
          if (attempt < 3) {
            await new Promise((resolve) => setTimeout(resolve, 100 * attempt));
          }
        }
      }
    }

    // All paths exhausted
    throw new Error(`Failed to load: ${resource}`);
  },

  // Get blob URL (with caching and 48 limit)
  async getBlobUrl(url, resource) {
    // Check cache first
    if (this.blobCache.has(resource)) {
      return this.blobCache.get(resource);
    }

    // Enforce 48 blob limit - release oldest
    if (this.blobCache.size >= this.maxBlobs) {
      const firstKey = this.blobCache.keys().next().value;
      const oldBlob = this.blobCache.get(firstKey);
      URL.revokeObjectURL(oldBlob);
      this.blobCache.delete(firstKey);
    }

    // Fetch blob
    const response = await fetch(url, {
      signal: AbortSignal.timeout(10000),
    });
    const blob = await response.blob();
    const blobUrl = URL.createObjectURL(blob);

    this.blobCache.set(resource, blobUrl);
    return blobUrl;
  },

  // Cleanup out-of-viewport blobs
  cleanupBlobs() {
    const viewportImages = Array.from(
      document.querySelectorAll("img[data-src]"),
    ).filter((img) => this.isNearViewport(img));

    const inViewportResources = new Set(
      viewportImages.map((img) => img.dataset.src),
    );

    for (const [resource, blobUrl] of this.blobCache.entries()) {
      if (!inViewportResources.has(resource)) {
        URL.revokeObjectURL(blobUrl);
        this.blobCache.delete(resource);
      }
    }
  },
};

// Cleanup blobs every 5 seconds
setInterval(() => medcrypt.cleanupBlobs(), 5000);
