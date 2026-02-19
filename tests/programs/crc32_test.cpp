// expect: 0
// toolchain: true
//
// Tests static locals, CRC32, pointer arithmetic, memcpy, sizeof,
// (void) casts, and typedef'd types.

extern "C" {
    int printf(const char* fmt, ...);
    void* memcpy(void* dest, const void* src, unsigned long n);
    void* malloc(unsigned long size);
    void free(void* ptr);
}

typedef int StatusType;
#define STATUS_FALSE 0
#define STATUS_TRUE  1
#define BUFFER_EXTENT 4096

typedef struct _DataBuffer {
    unsigned char* datum;
    unsigned long  length;
} DataBuffer;

static DataBuffer* CreateBuffer(unsigned long length) {
    DataBuffer* buf = (DataBuffer*)malloc(sizeof(DataBuffer));
    if (buf == (DataBuffer*)0) return (DataBuffer*)0;
    buf->datum = (unsigned char*)malloc(length);
    buf->length = length;
    unsigned long i;
    for (i = 0; i < length; i++)
        buf->datum[i] = 0;
    return buf;
}

static unsigned char* GetBufferData(const DataBuffer* buf) {
    return buf->datum;
}

static unsigned long GetBufferLength(const DataBuffer* buf) {
    return buf->length;
}

static void SetBufferLength(DataBuffer* buf, unsigned long length) {
    buf->length = length;
}

static void ConcatenateBuffers(DataBuffer* dst, const DataBuffer* src) {
    unsigned long i;
    for (i = 0; i < src->length && (dst->length + i) < BUFFER_EXTENT; i++)
        dst->datum[dst->length + i] = src->datum[i];
    dst->length = dst->length + i;
}

static DataBuffer* DestroyBuffer(DataBuffer* buf) {
    free(buf->datum);
    free(buf);
    return (DataBuffer*)0;
}

static unsigned int ComputeCRC32(const unsigned char* message, const unsigned long length) {
    long i;

    static StatusType
        crc_initial = STATUS_FALSE;

    static unsigned int
        crc_xor[256];

    unsigned int crc;

    if (crc_initial == STATUS_FALSE) {
        unsigned int j;
        unsigned int alpha;

        for (j = 0; j < 256; j++) {
            long k;
            alpha = j;
            for (k = 0; k < 8; k++)
                alpha = (alpha & 0x01) ? (0xEDB88320 ^ (alpha >> 1)) : (alpha >> 1);
            crc_xor[j] = alpha;
        }
        crc_initial = STATUS_TRUE;
    }
    crc = 0xFFFFFFFF;
    for (i = 0; i < (long)length; i++)
        crc = crc_xor[(crc ^ message[i]) & 0xff] ^ (crc >> 8);
    return (crc ^ 0xFFFFFFFF);
}

static unsigned int ComputeSignature(const DataBuffer* nonce) {
    unsigned char* p;
    DataBuffer* version;
    unsigned int signature;

    version = CreateBuffer(BUFFER_EXTENT);
    p = GetBufferData(version);

    signature = 16;
    (void)memcpy(p, &signature, sizeof(signature));
    p = p + sizeof(signature);

    signature = 0;
    (void)memcpy(p, &signature, sizeof(signature));
    p = p + sizeof(signature);

    signature = 0x711;
    (void)memcpy(p, &signature, sizeof(signature));
    p = p + sizeof(signature);

    signature = 1;
    (void)memcpy(p, &signature, sizeof(signature));
    p = p + sizeof(signature);

    SetBufferLength(version, (unsigned long)(p - GetBufferData(version)));
    if (nonce != (const DataBuffer*)0)
        ConcatenateBuffers(version, nonce);
    signature = ComputeCRC32(GetBufferData(version), GetBufferLength(version));
    version = DestroyBuffer(version);
    return signature;
}

static const char* GetCopyright(void) {
    return "Copyright (C) 2024 Test Project";
}

static const char* GetPackageName(void) {
    return "TestPackage";
}

static const char* GetReleaseDate(void) {
    return "2025-03-29";
}

static const char* GetVersionString(unsigned long* version) {
    if (version != (unsigned long*)0)
        *version = 0x711;
    return "TestPackage 7.1.1-47 x86_64 2025-03-29";
}

static const char* GetQuantumDepth(unsigned long* depth) {
    if (depth != (unsigned long*)0)
        *depth = 16;
    return "Q16";
}

int main(void) {
    printf("pkg=%s\n", GetPackageName());
    printf("date=%s\n", GetReleaseDate());
    printf("copyright=%s\n", GetCopyright());

    unsigned long ver;
    const char* verStr = GetVersionString(&ver);
    printf("version=0x%x\n", (unsigned int)ver);
    printf("verstr=%s\n", verStr);

    unsigned long depth;
    const char* qdStr = GetQuantumDepth(&depth);
    printf("depth=%d qdstr=%s\n", (int)depth, qdStr);

    const char* v2 = GetVersionString((unsigned long*)0);
    printf("v2=%s\n", v2);

    unsigned int sig = ComputeSignature((const DataBuffer*)0);
    printf("sig=0x%x\n", sig);

    unsigned int sig2 = ComputeSignature((const DataBuffer*)0);
    printf("match=%d\n", sig == sig2 ? 1 : 0);

    unsigned int crc = ComputeCRC32((const unsigned char*)"hello", 5);
    printf("crc=0x%x\n", crc);

    return 0;
}
