// expect: 0
// toolchain: true
extern "C" {
    int printf(const char* fmt, ...);
}

int main() {
    printf("%d\n", 42);
    printf("%d %d %d\n", 1, 2, 3);
    return 0;
}
