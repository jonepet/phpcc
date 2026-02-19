// expect: 4
// toolchain: true
union Data {
    int i;
    char c;
};

int main() {
    return sizeof(union Data);
}
