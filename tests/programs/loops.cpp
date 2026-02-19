// expect: 60
int main() {
    int sum = 0;
    for (int i = 1; i <= 10; i++) {
        sum = sum + i;
    }
    // sum should be 55

    int j = 0;
    while (j < 5) {
        j++;
    }
    // j should be 5

    return sum + j;  // 60
}
